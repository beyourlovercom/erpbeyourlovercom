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
                (release / "app").mkdir(parents=True)
                (release / "app/Example.php").write_text("<?php // shared backend\n")
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
            self.assertTrue(report["production_worker_backend_drift"])
            scheduler = next(w for w in report["production_workers"]
                             if w["role"] == "schedule:work")
            self.assertEqual(scheduler["missing_scheduled_commands"],
                             ["shipout:reconcile-oms"])
            self.assertEqual(report["dev_artisan_launchers"], 1)
            self.assertEqual(report["dev_php_http_processes"], 1)
            self.assertTrue(report["read_only"])


    def test_ui_only_release_has_path_drift_but_backend_parity(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            old = root / "releases" / "pr692"
            new = root / "releases" / "pr693"
            for release in (old, new):
                (release / "routes").mkdir(parents=True)
                (release / "app").mkdir(parents=True)
                (release / "app/Example.php").write_text("<?php // same worker code")
                (release / "routes/console.php").write_text(
                    "Schedule::command('orders:sync-woocommerce')->hourly();"
                )
            (new / "resources/js").mkdir(parents=True)
            (new / "resources/js/app.ts").write_text("const newGridHeight = 159")
            (root / "current").symlink_to(new, target_is_directory=True)
            output = "101 10240 php artisan queue:work redis\n"
            report = audit.inventory(root, ps_output=output,
                                     cwd_lookup=lambda pid: old)
            self.assertTrue(report["production_worker_drift"])
            self.assertFalse(report["production_worker_backend_drift"])
            self.assertTrue(report["production_workers"][0]["backend_compatible"])

            (new / "app/Example.php").write_text("<?php // changed worker code")
            report = audit.inventory(root, ps_output=output,
                                     cwd_lookup=lambda pid: old)
            self.assertTrue(report["production_worker_backend_drift"])
            self.assertFalse(report["production_workers"][0]["backend_compatible"])

    def test_missing_backend_source_fails_closed(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            old = root / "releases" / "old"
            new = root / "releases" / "new"
            (old / "routes").mkdir(parents=True)
            (new / "app").mkdir(parents=True)
            (new / "routes").mkdir()
            (new / "app/Example.php").write_text("<?php")
            (root / "current").symlink_to(new, target_is_directory=True)
            report = audit.inventory(
                root, ps_output="20 10000 php artisan schedule:work\n",
                cwd_lookup=lambda pid: old,
            )
            self.assertTrue(report["production_worker_backend_drift"])


    def test_worker_minimums_fail_closed_without_scheduler_or_batch(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            release = root / "releases" / "current"
            (release / "app").mkdir(parents=True)
            (release / "routes").mkdir()
            (release / "app/Example.php").write_text("<?php")
            (release / "routes/console.php").write_text("<?php")
            (root / "current").symlink_to(release, target_is_directory=True)
            one_worker = audit.inventory(
                root, ps_output="101 20000 php artisan queue:work redis\n",
                cwd_lookup=lambda _: release,
            )
            self.assertFalse(one_worker["minimum_workers_present"])
            all_workers = audit.inventory(
                root, ps_output=(
                    "101 20000 php artisan queue:work redis\n"
                    "102 20000 php artisan queue:work redis --queue=shipout-production-batch\n"
                    "103 12000 php artisan schedule:work\n"
                ),
                cwd_lookup=lambda _: release,
            )
            self.assertTrue(all_workers["minimum_workers_present"])
            self.assertFalse(all_workers["production_worker_backend_drift"])


if __name__ == "__main__":
    unittest.main()
