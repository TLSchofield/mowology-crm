"""The reasons you can pick when you decide. Each one teaches the agents something specific (see TEACHES), so the department learns from a click.
A free-text detail is always optional, except for 'other'."""

REASONS = {
    "approve": [("revenue", "It helps us win or keep customers"), ("fixes_problem", "It fixes a real problem customers hit"), ("cheap_clear", "Small, low-risk and clearly right"),
                ("must_do", "We have to do this (legal, security or safety)"), ("other", "Other (say why)")],
    "reject": [("not_now", "Good idea, not now (ask again in 90 days)"), ("deliberate", "It's deliberate: we want it this way"), ("low_value", "Not worth the effort for what it gives"),
               ("wrong_facts", "The evidence is wrong or out of date"), ("too_big", "Too big or risky: bring a smaller version"), ("other", "Other (say why)")],
    "revise": [("smaller", "Make it smaller"), ("different_way", "Right problem, different solution"), ("more_evidence", "Show me more evidence first"),
               ("part_only", "Only part of it"), ("other", "Other (say why)")],
    "unblock": [("fixed", "I fixed the cause"), ("not_real", "It wasn't a real problem"), ("retry", "Just try again"), ("other", "Other (say why)")],
    "release": [("checked", "I checked the branch and it looks right"), ("urgent", "It's urgent"), ("other", "Other (say why)")],
}

TEACHES = {
    "approve": {"revenue": "weight money-making ideas higher", "fixes_problem": "weight customer-facing defects higher", "cheap_clear": "small clear wins are welcome",
                "must_do": "legal, security and safety items go first", "other": "read the note"},
    "reject": {"not_now": "do not raise this again for 90 days", "deliberate": "never raise this again without significant new evidence; it is a design decision",
               "low_value": "similar low-impact ideas are not worth proposing", "wrong_facts": "your evidence was wrong: re-verify before claiming it, and count it as an accuracy miss",
               "too_big": "this may come back only as a clearly smaller version", "other": "read the note"},
    "revise": {"smaller": "propose a smaller version", "different_way": "keep the problem, change the solution", "more_evidence": "bring measurements before proposing again",
               "part_only": "keep only the part the note names", "other": "read the note"},
}


def label(decision, code):
    return next((l for c, l in REASONS.get(decision, []) if c == code), None)


def valid(decision, code):
    return label(decision, code) is not None


def compose(decision, code, note):
    """The sentence stored with the decision: the label, plus the optional detail."""
    lab = label(decision, code)
    if lab is None:
        raise ValueError("unknown reason for %s" % decision)
    note = (note or "").strip()
    if code == "other" and not note:
        raise ValueError("please say why when you choose Other")
    return lab + ((": " + note) if note else "")
