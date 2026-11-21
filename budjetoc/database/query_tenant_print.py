import re
from pathlib import Path
import pymysql

r = Path(__file__).resolve().parents[1] / "config" / "config.php"
text = r.read_text(encoding="utf-8", errors="replace")

def grab(key, default=""):
    m = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
    return m.group(1) if m else default

conn = pymysql.connect(
    host=grab("host", "127.0.0.1"),
    user=grab("user", "root"),
    password=grab("pass", ""),
    database=grab("name", "budjetoc"),
    charset="utf8mb4",
)
cur = conn.cursor()
cur.execute(
    """SELECT print_contact_name, print_contact_role, print_contact_email,
              print_delivery_hours, print_delivery_address, print_invoice_to,
              print_footer_text, company_name, address, tax_id, phone, business_line
       FROM tenant_settings WHERE tenant_id = 2"""
)
print(cur.fetchone())
conn.close()
