"""Regression tests for exact tree comparison and fail-closed deployment evidence."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("environment_state", Path(__file__).with_name("environment-state.py"))
state = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(state)


class EnvironmentStateTests(unittest.TestCase):
    def setUp(self):
        self.old_cwd = Path.cwd()
        self.temp = tempfile.TemporaryDirectory()
        os.chdir(self.temp.name)
        self.addCleanup(self.restore)
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Test")
        self.write(".gitignore", "storage/\n")
        self.write("app/Foo.php", "first")
        self.write("composer.lock", "locked")
        self.write(".github/workflows/ci.yml", "first workflow")
        self.write("docs/readme.md", "documentation")
        self.write("public/build/manifest.json", "{}")
        self.commit("baseline")
        self.baseline = self.git("rev-parse", "HEAD")

    def restore(self):
        os.chdir(self.old_cwd)
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(["git", *args], text=True).strip()

    def write(self, path, content):
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(content)

    def commit(self, msg):
        self.git("add", "-A")
        self.git("commit", "-qm", msg)

    def test_same_tree_different_history(self):
        self.git("commit", "--allow-empty", "-qm", "different history")
        result = state.compare(self.baseline, "HEAD")
        self.assertNotEqual(result["left"]["git_sha"], result["right"]["git_sha"])
        self.assertTrue(result["all_tracked_equal"])
        self.assertTrue(result["functional_equal"])

    def test_service_changes_separate_from_functional(self):
        self.write(".github/workflows/ci.yml", "other workflow")
        self.write("docs/readme.md", "updated")
        self.write("public/build/manifest.json", '{"new":true}')
        self.commit("nonfunctional changes")
        result = state.compare(self.baseline, "HEAD")
        self.assertTrue(result["functional_equal"])
        self.assertFalse(result["all_tracked_equal"])
        for key in ("workflows", "documentation", "generated"):
            self.assertEqual(1, len(result["differences"][key]))

    def test_added_removed_changed_and_executable(self):
        self.write("app/Foo.php", "second")
        self.write("app/Bar.php", "added")
        Path("composer.lock").unlink()
        self.commit("functional changes")
        result = state.compare(self.baseline, "HEAD")
        self.assertFalse(result["functional_equal"])
        self.assertEqual({"app/Bar.php": "added", "app/Foo.php": "modified", "composer.lock": "removed"},
                         {x["path"]: x["status"] for x in result["differences"]["functional"]})
        old = self.git("rev-parse", "HEAD")
        Path("app/Bar.php").chmod(0o755)
        self.commit("mode change")
        self.assertFalse(state.compare(old, "HEAD")["functional_equal"])

    def test_deploy_record_and_dirty_checkout(self):
        self.assertFalse(state.inspect()["checkpoint_matches_checkout"])
        with self.assertRaises(RuntimeError):
            state.record("production", "0" * 40, "1")
        self.write("public/build/manifest.json", "rebuilt")
        result = state.record("production", self.baseline, "1")
        self.assertEqual(self.baseline, result["git_sha"])
        self.assertTrue(state.inspect()["checkpoint_matches_checkout"])
        self.write("app/Foo.php", "dirty")
        self.assertFalse(state.inspect()["checkpoint_matches_checkout"])
        self.assertIn("app/Foo.php", state.inspect()["tracked_and_unignored_source_drift"])
        with self.assertRaises(RuntimeError):
            state.record("production", self.baseline, "2")
        self.assertEqual("1", state.inspect()["last_verified_deploy"]["workflow_run_id"])

    def test_untracked_source_and_symlink(self):
        self.write("app/Unexpected.php", "untracked")
        self.assertFalse(state.inspect()["source_clean"])
        Path("app/Unexpected.php").unlink()
        Path("app/link").symlink_to("Foo.php")
        self.commit("symlink")
        self.assertTrue(state.inspect()["source_clean"])
        Path("app/link").unlink()
        Path("app/link").symlink_to("different")
        self.assertFalse(state.inspect()["source_clean"])


if __name__ == "__main__":
    unittest.main()
