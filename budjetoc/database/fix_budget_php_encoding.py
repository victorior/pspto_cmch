#!/usr/bin/env python3
"""Corrige caracteres corruptos en public/oc/budget.php."""
from __future__ import annotations

import re
from pathlib import Path

BUDGET = Path(__file__).resolve().parents[1] / "public" / "oc" / "budget.php"

REPLACEMENT = """  <h2 style="margin:0 0 0.5rem;font-size:1.05rem;">
    <?= h((string) ($result['activity_number'] ?? '')) ?>
    <?= h(' ' . t_es('sep_title') . ' ') ?>
    <?= h((string) ($result['activity_name'] ?? '')) ?>
  </h2>
  <p class="text-muted" style="margin:0 0 0.5rem;font-size:0.9rem;">
    <?= h(t_es('mes')) ?> <?= h($bfBudgetId($result['month'] ?? null)) ?>
    <?= h(' ' . t_es('sep_middle') . ' ') ?>
    <?= h(t_es('total')) ?> <?= h(budget_int_fmt((float) ($result['total'] ?? 0))) ?>
    <?= h(' ' . t_es('sep_middle') . ' ') ?>
    <?= h(t_es('utilizado')) ?> <?= h(budget_int_fmt((float) ($result['occupied'] ?? 0))) ?>
    <?= h(' ' . t_es('sep_middle') . ' ') ?>
    <?= h(t_es('quedan')) ?> <?= h(budget_int_fmt((float) ($result['remaining'] ?? 0))) ?>
  </p>

  <?php if ($detailErr)"""


def main() -> int:
    t = BUDGET.read_text(encoding="utf-8", errors="replace")
    t2, n = re.subn(
        r"  <h2 style=\"margin:0 0 0\.5rem;font-size:1\.05rem;\">.*?  <\?php if \(\$detailErr\)",
        REPLACEMENT,
        t,
        count=1,
        flags=re.DOTALL,
    )
    if n == 0:
        print("NO MATCH")
        return 1
    t2 = t2.replace("\ufffd", "")
    BUDGET.write_text(t2, encoding="utf-8", newline="\n")
    print("OK: budget.php encoding fixed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
