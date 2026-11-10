"""Tests for UAT inventory grouping, exact scope, and no-termination policy."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).resolve().parents[2] / "scripts/ops/erp_uat_server_inventory.py"
spec = importlib.util.spec_from_file_location("erp_uat_server_inventory", MODULE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class UatInventoryTests(unittest.TestCase):
    def test_parent_child_and_unauthorized_scope_excluded(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "uat"
            (root / "erp-demo").mkdir(parents=True)
            prod = Path(folder) / "production"
            prod.mkdir()
            text = (
                "101 1 2-01:00:01 10240 php artisan serve --port=56855\n"
                "102 101 2-01:00:00 8192 /opt/homebrew/bin/php -S 127.0.0.1:56855 "
                + str(root / "erp-demo" / "server.php") + "\n"
                "103 1 0-01:30:00 10240 php artisan serve --port=8000\n"
                "104 1 0-10:00:00 8192 /opt/homebrew/bin/php -S 127.0.0.1:56901 "
                + str(root / "erp-demo" / "server.php") + "\n"
                "105 1 5-00:00:00 8192 /opt/homebrew/bin/php -S 127.0.0.1:9000 "
                + str(prod / "server.php") + "\n"
            )
            cwd = {101: root / "erp-demo", 103: prod}
            report = audit.inventory(root, ps_output=text,
                                     cwd_lookup=lambda pid: cwd.get(pid))
            self.assertEqual(report["counts"], {
                "artisan_launchers": 1,
                "paired_php_http_children": 1,
                "orphan_php_http_candidates": 1,
            })
            self.assertEqual(report["artisan_launchers"][0]["http_children"], [102])
            self.assertEqual(report["orphan_php_http_candidates"][0]["pid"], 104)
            self.assertEqual(report["safety"], "INVENTORY_ONLY_DO_NOT_AUTO_TERMINATE")

    def test_parser_requires_valid_ps_fields(self):
        rows = audit.parse_processes("123 1 04:00 2048 php artisan serve\nbad\n")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["rss_kb"], 2048)


if __name__ == "__main__":
    unittest.main()
