"""Feldlayout (Boxen in Referenzkoordinaten) laden — erzeugt von tools/learn_layout.py."""
import json
import os

DEFAULT_DIR = os.path.join(os.path.dirname(__file__), "..", "reference")


class Layout:
    def __init__(self, path=None):
        path = path or os.path.join(DEFAULT_DIR, "layout.json")
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        self.width = data["reference"]["width"]
        self.height = data["reference"]["height"]
        self.line_height = data.get("line_height", 40)
        self.panels = data.get("panels", [])
        self.fields = data["fields"]  # name -> {"box": [...], "multiline": bool, ...}
        # Panels mit gedrucktem Zeilenraster (Feldpanels), Zeilenabstand in Referenz-Pixeln
        self.rule_panels = set(data.get("rule_panels", [1, 2]))
        self.row_pitch = data.get("row_pitch", 53.0)

    def items(self):
        return self.fields.items()

    def box(self, name):
        return self.fields[name]["box"]

    def multiline(self, name):
        return bool(self.fields[name].get("multiline"))
