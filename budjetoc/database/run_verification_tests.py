#!/usr/bin/env python3
"""Verificacion integral post-cambios (esquema, reglas negocio, consistencia)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def db_cfg() -> dict:
    text = (ROOT / "config" / "config.php").read_text(encoding="utf-8", errors="replace")

    def grab(key: str, default: str = "") -> str:
        m = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        return m.group(1) if m else default

    return {
        "host": grab("host", "127.0.0.1"),
        "user": grab("user", "root"),
        "password": grab("pass", ""),
        "database": grab("name", "budjetoc"),
        "charset": "utf8mb4",
    }


class T:
  ok = 0
  fail = 0
  warnings = 0

  def check(self, name: str, cond: bool, detail: str = "") -> None:
    if cond:
      self.ok += 1
      print(f"  OK   {name}" + (f" — {detail}" if detail else ""))
    else:
      self.fail += 1
      print(f"  FAIL {name}" + (f" — {detail}" if detail else ""))

  def warning(self, name: str, detail: str = "") -> None:
    self.warnings += 1
    print(f"  WARN {name}" + (f" — {detail}" if detail else ""))


def table_exists(cur, name: str) -> bool:
  cur.execute(f"SHOW TABLES LIKE '{name}'")
  return cur.fetchone() is not None


def column_exists(cur, table: str, col: str) -> bool:
  cur.execute(f"SHOW COLUMNS FROM `{table}` LIKE %s", (col,))
  return cur.fetchone() is not None


def index_exists(cur, table: str, index_name: str) -> bool:
  cur.execute(f"SHOW INDEX FROM `{table}` WHERE Key_name = %s", (index_name,))
  return cur.fetchone() is not None


def main() -> int:
  try:
    import pymysql
  except ImportError:
    print("FAIL: pip install pymysql")
    return 1

  t = T()
  print("=== Budjetoc — verificacion por CMD ===\n")
  cfg = db_cfg()
  print(f"BD: {cfg['database']} @ {cfg['host']}\n")

  conn = pymysql.connect(**cfg)
  cur = conn.cursor()

  print("[1] Esquema / migraciones")
  t.check("budget_year existe", column_exists(cur, "budget_headers", "budget_year"))
  t.check("voided_at existe", column_exists(cur, "purchase_orders", "voided_at"))
  t.check("idx_bh_tenant_year_month", index_exists(cur, "budget_headers", "idx_bh_tenant_year_month"))
  t.check("idx_po_tenant_year_status_num", index_exists(cur, "purchase_orders", "idx_po_tenant_year_status_num"))
  t.check("external_budget_detail_fields eliminada", not table_exists(cur, "external_budget_detail_fields"))
  t.check("external_activity_fields eliminada", not table_exists(cur, "external_activity_fields"))

  print("\n[2] budget_year — datos")
  cur.execute("SELECT COUNT(*) FROM budget_headers WHERE budget_year = 0")
  zero_year = int(cur.fetchone()[0])
  cur.execute("SELECT COUNT(*) FROM budget_headers")
  total_bh = int(cur.fetchone()[0])
  t.check("budget_year poblado", zero_year == 0 or total_bh == 0, f"{zero_year} sin año de {total_bh} cabeceras")

  cur.execute(
    """SELECT COUNT(*) FROM budget_headers
       WHERE budget_year <> CAST(SUBSTRING_INDEX(activity_number, '-', -1) AS UNSIGNED)
         AND activity_number LIKE '%-%'"""
  )
  mismatch = int(cur.fetchone()[0])
  t.check("budget_year coincide con activity_number", mismatch == 0, f"{mismatch} discrepancias")

  print("\n[3] voided_at — OCs anuladas")
  cur.execute("SELECT COUNT(*) FROM purchase_orders WHERE status = 2")
  annulled = int(cur.fetchone()[0])
  cur.execute("SELECT COUNT(*) FROM purchase_orders WHERE status = 2 AND voided_at IS NULL")
  no_void = int(cur.fetchone()[0])
  t.check("OC anuladas con voided_at", no_void == 0 or annulled == 0, f"{no_void}/{annulled} sin fecha")

  print("\n[4] Regla soft: utilizado excluye OC anuladas")
  cur.execute(
    """SELECT bd.id AS detail_id,
              COALESCE(SUM(CASE WHEN po.status <> 2 THEN pba.amount ELSE 0 END), 0) AS active_sum,
              COALESCE(SUM(pba.amount), 0) AS all_sum
       FROM budget_details bd
       LEFT JOIN po_budget_allocations pba ON pba.budget_detail_id = bd.id
       LEFT JOIN purchase_orders po ON po.id = pba.purchase_order_id AND po.tenant_id = pba.tenant_id
       GROUP BY bd.id
       HAVING all_sum > active_sum + 0.01
       LIMIT 5"""
  )
  rows = cur.fetchall()
  t.check(
    "Hay imputaciones en OC anuladas (historial)",
    len(rows) > 0 or annulled == 0,
    f"{len(rows)} lineas con diff activo/total (muestra)",
  )

  cur.execute(
    """SELECT bh.id, bh.activity_number,
              (SELECT COALESCE(SUM(quantity*unit_price),0) FROM budget_details WHERE budget_header_id=bh.id) AS presupuesto,
              (SELECT COALESCE(SUM(pba.amount),0) FROM budget_details bd
               JOIN po_budget_allocations pba ON pba.budget_detail_id=bd.id
               JOIN purchase_orders po ON po.id=pba.purchase_order_id AND po.status<>2
               WHERE bd.budget_header_id=bh.id) AS utilizado_vigente
       FROM budget_headers bh
       WHERE EXISTS (
         SELECT 1 FROM po_budget_allocations pba2
         JOIN budget_details bd2 ON bd2.id=pba2.budget_detail_id
         JOIN purchase_orders po2 ON po2.id=pba2.purchase_order_id
         WHERE bd2.budget_header_id=bh.id AND po2.status=2
       )
       LIMIT 3"""
  )
  sample = cur.fetchall()
  for hid, act, pres, util in sample:
    t.check(
      f"Actividad {act}: saldo coherente con anuladas",
      float(pres) >= float(util) - 0.01,
      f"pres={pres} util_vigente={util}",
    )

  print("\n[5] Filtro por año (budget_year vs SUBSTRING)")
  cur.execute("SELECT tenant_id FROM budget_headers LIMIT 1")
  tid_row = cur.fetchone()
  if tid_row:
    tid = int(tid_row[0])
    cur.execute("SELECT MAX(budget_year) FROM budget_headers WHERE tenant_id=%s", (tid,))
    year = int(cur.fetchone()[0] or 2026)
    cur.execute(
      "SELECT COUNT(*) FROM budget_headers WHERE tenant_id=%s AND budget_year=%s",
      (tid, year),
    )
    c1 = int(cur.fetchone()[0])
    cur.execute(
      """SELECT COUNT(*) FROM budget_headers WHERE tenant_id=%s
         AND CAST(SUBSTRING_INDEX(activity_number,'-',-1) AS UNSIGNED)=%s""",
      (tid, year),
    )
    c2 = int(cur.fetchone()[0])
    t.check(f"Conteo año {year} (tenant {tid}) budget_year vs parseo", c1 == c2, f"{c1} vs {c2}")
  else:
    t.warning("Sin actividades en BD — omitiendo test de año")

  print("\n[6] Archivos PHP (sintaxis)")
  php_files = [
    ROOT / "src/BudgetService.php",
    ROOT / "src/OcService.php",
    ROOT / "src/OcPrintView.php",
    ROOT / "src/PurchaseOrderStatus.php",
    ROOT / "src/UserPermissions.php",
    ROOT / "public/oc/print.php",
    ROOT / "public/oc/view_from_activity.php",
    ROOT / "public/oc/inc/oc_print_render.php",
  ]
  import shutil
  import subprocess

  php = shutil.which("php")
  if not php:
    for p in [
      r"D:\xampp\php\php.exe",
      r"C:\xampp\php\php.exe",
      r"C:\laragon\bin\php\php-8.3.12-Win32-vs16-x64\php.exe",
    ]:
      if Path(p).is_file():
        php = p
        break
  if php:
    for f in php_files:
      if not f.is_file():
        t.warning(f"Falta {f.name}")
        continue
      r = subprocess.run([php, "-l", str(f)], capture_output=True, text=True)
      t.check(f"Sintaxis {f.name}", r.returncode == 0, (r.stdout or r.stderr or "").strip()[:80])
  else:
    t.warning("PHP no encontrado — omitiendo lint")

  conn.close()

  print("\n=== Resumen ===")
  print(f"  OK: {t.ok}  FAIL: {t.fail}  WARN: {t.warnings}")
  return 0 if t.fail == 0 else 1


if __name__ == "__main__":
  raise SystemExit(main())
