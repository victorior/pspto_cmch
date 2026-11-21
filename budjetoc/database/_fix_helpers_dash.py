from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "src" / "helpers.php"
t = p.read_text(encoding="utf-8", errors="replace")
t2 = re.sub(r"return '[^']*';(\s*\n\})", r'return "\u{2014}";\1', t, count=1)
if t2 == t:
    t2 = t.replace("return '\ufffd';", 'return "\u{2014}";')
p.write_text(t2, encoding="utf-8", newline="\n")
print("changed", t != t2)
