"""Regression: a group's existing indentation must not redirect or block registration."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('bridge', Path(__file__).parents[1] / 'add_swift_file.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

class SwiftBridgeChecks(unittest.TestCase):
    def test_mixed_indentation_stays_in_correct_group(self):
        for indent in ['', '\t\t\t', '    ']:
            source = '/* Core */ = {\nisa = PBXGroup;\nchildren = (\n OLD /* Design */,\n' + indent + ');\npath = Core;\n};\n/* Next */ = {\nisa = PBXGroup;\nchildren = (\n OTHER,\n);\npath = Next;\n};'
            actual = bridge.add_group_child(source, 'Core', 'NEW /* Diagnostics */')
            before, after = actual.split('/* Next */')
            self.assertIn('NEW /* Diagnostics */', before)
            self.assertNotIn('NEW', after)

    def test_missing_group_fails_without_writing(self):
        with self.assertRaises(SystemExit):
            bridge.add_group_child('/* Other */ = { path = Other; };', 'Core', 'NEW')

if __name__ == '__main__': unittest.main()
