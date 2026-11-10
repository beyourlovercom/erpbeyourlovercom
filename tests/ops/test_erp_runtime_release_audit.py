"""Unit tests for read-only production release drift classification."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).resolve().parents[2] / "scripts/ops/erp_runtime_release_audit.py"
spec = importlib.util.spec_from_file_location("erp_release_audit", MODULE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class RuntimeAuditTests(unittest.TestCase):
    def test_ps_parser_ignores_invalid_lines(self):
        self.assertEqual(
            audit.process_rows("1 120 php artisan serve --port=50\nbad\n2 80 php artisan queue:work"),
            [(1, 120, "php artisan serve --port=50"),
             (2, 80, "php artisan queue:work")],
        )

    def test_release_drift_and_scheduler_command_diff(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            old = root / "releases" / "old"
            new = root / "releases" / "new"
            for release in (old, new):
                (release / "routes").mkdir(parents=True)
            (old / "routes/console.php").write_text(
                "Schedule::command('orders:sync-woocommerce --apply')->hourly();\n"
            )
            (new / "routes/console.php").write_text(
                "Schedule::command('orders:sync-woocommerce --apply')->hourly();\n"
                "Schedule::command('shipout:reconcile-oms --apply')->everyThirtyMinutes();\n"
            )
            (root / "current").symlink_to(new, target_is_directory=True)
            output = (
                "101 10000 php artisan schedule:work\n"
                "102 20000 php artisan queue:work redis\n"
                "103 30000 php artisan serve --port=56855\n"
                "104 11000 /opt/homebrew/bin/php -S 127.0.0.1:56855\n"
                "105 12000 php artisan schedule:work\n"
            )
            mapping = {101: old, 102: new, 103: root, 104: root,
                       105: root / "localdev"}
            report = audit.inventory(root, ps_output=output,
                                     cwd_lookup=lambda pid: mapping.get(pid))
            self.assertEqual(len(report["production_workers"]), 2)
            self.assertTrue(report["production_worker_drift"])
            scheduler = next(w for w in report["production_workers"]
                             if w["role"] == "schedule:work")
            self.assertEqual(scheduler["missing_scheduled_commands"],
                             ["shipout:reconcile-oms"])
            self.assertEqual(report["dev_artisan_launchers"], 1)
            self.assertEqual(report["dev_php_http_processes"], 1)
            self.assertTrue(report["read_only"])


if __name__ == "__main__":
    unittest.main()
