import subprocess
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
script = root / 'database' / 'import_budgetflow_users.php'
candidates = [
    r'D:\xampp\php\php.exe',
    r'C:\xampp\php\php.exe',
    r'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe',
    r'C:\laragon\bin\php\php-8.2.12-Win32-vs16-x64\php.exe',
    r'C:\wamp64\bin\php\php8.2.0\php.exe',
    r'C:\php\php.exe',
]
php = None
for c in candidates:
    if Path(c).is_file():
        php = c
        break
if php is None:
  php = 'php'
print('Using:', php)
r = subprocess.run([php, str(script)], cwd=str(root), capture_output=True, text=True)
print(r.stdout)
if r.stderr:
    print(r.stderr, file=sys.stderr)
sys.exit(r.returncode)
