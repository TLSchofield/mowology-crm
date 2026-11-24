#!/usr/bin/env python3
"""Stand-in for the Claude CLI used by tests and `ada demo-commercial`. It returns CANNED, SYNTHETIC answers (never real data) in the CLI's
JSON envelope, chosen by the --agent name. STANDIN_FAIL=<agent> makes that agent fail; STANDIN_FAIL_ONCE_FILE=<path> fails it only the first time."""
import json, os, sys
a = sys.argv
agent = a[a.index("--agent") + 1] if "--agent" in a else "?"
fail = os.environ.get("STANDIN_FAIL")
once = os.environ.get("STANDIN_FAIL_ONCE_FILE")
if fail == agent:
    print("simulated agent failure", file=sys.stderr); sys.exit(1)
if once and agent == os.environ.get("STANDIN_FAIL_ONCE_AGENT") and not os.path.exists(once):
    open(once, "w").write("failed once"); print("simulated one-off failure", file=sys.stderr); sys.exit(1)
SRC = "demo fixture (synthetic, not real data)"
OUT = {
 "compass": {"segments": [{"name": "landscaping-bc-1-10-staff", "evidence": [{"fact": "SYNTHETIC: owner-operated crews of 1-10 are common in the segment", "source": SRC, "observed_at": "2026-11-24"}],
              "fit_reasoning": "Inference: Mowology's own workflow matches this segment", "caveats": ["not validated with real owners"]}],
             "icp_hypothesis": {"who": "owner-operated landscaping business, 1-10 field staff, Lower Mainland BC", "signals": ["quotes by hand", "evenings spent on admin"], "disqualifiers": ["enterprise franchise"], "validated": False},
             "unknowns": ["real willingness to pay", "which current software they use"]},
 "hunter": {"prospect": {"business_name": "Evergreen Row Landscaping (SYNTHETIC)", "website": "https://evergreenrow.example", "industry": "landscaping", "location": "Burnaby, BC",
            "contact": {"email": "hello@evergreenrow.example"},
            "qualification_evidence": [{"fact": "SYNTHETIC: site lists lawn care, garden maintenance and seasonal clean-ups", "source": SRC, "observed_at": "2026-11-24"},
                                        {"fact": "SYNTHETIC: site has a contact form but no online quote option", "source": SRC, "observed_at": "2026-11-24"}],
            "inferred_needs": ["INFERENCE (no evidence they struggle): may benefit from faster quoting, resting on the missing online quote option"],
            "fit_factors": {"product_fit": "medium — services match", "evidence_of_need": "weak — one observation", "accessibility": "business email published"}}},
 "scribe": {"channel": "email", "subject": "A question about how you quote jobs",
            "body": "Hello,\n\nI run Mowology, a landscaping business in Vancouver, and built software to cut my own quoting and admin time. Ittai lets you build, send and track quotes in one place. If that is useful I can show you how it works on a real quote.\n\nTim, Mowology, [sender mailing address], [sender phone]\nIf you do not want to hear from me, reply 'unsubscribe' and I will not contact you again.",
            "claims": ["Quotes: build, send and track quotes"], "messaging_notes": {"target": "owner-operator", "awareness_level": "problem-aware", "problem": "slow quoting", "outcome": "less evening admin", "objection": "switching cost", "next_action": "offer a short demo"}},
 "apollo": {"decision": "advance", "rationale": "Services match; evidence of need is thin, so the first step is a low-pressure conversation, not a pitch.", "value": None,
            "next_action": "Owner reviews eligibility basis, then approves the draft", "next_action_date": "2026-11-27",
            "eligibility": {"basis": "conspicuous_publication", "evidence": "SYNTHETIC: business email published on its own website with no statement refusing unsolicited messages; message is about quoting, relevant to the owner's role. Owner to confirm."}},
 "closer": {"discovery_questions": ["How do you put a quote together today, start to finish?", "What part of your admin do you put off until the evening?"],
            "demo_plan": [{"scenario": "Quote a typical garden clean-up from a phone photo and site notes", "capabilities": ["Quotes: build, send and track quotes"], "talking_points": ["build the quote", "send it", "see when it is opened"], "honest_limits": ["online card payments are not offered"]}],
            "anticipated_objections": [{"wording": "I already use another app", "concern": "switching cost", "response": "Ask what it does not do for them; no pressure", "evidence": "none yet - anticipated", "capability": "Quotes: build, send and track quotes", "limitation": "import tooling still limited"}]},
 "mercury": {"findings": [{"topic": "seo", "statement": "SYNTHETIC: sitemap missing", "evidence": [{"fact": "sitemap.xml returned 404", "source": SRC, "observed_at": "2026-11-24"}], "severity": "medium", "proposed_fix": "publish sitemap.xml"}]},
}
print(json.dumps({"is_error": False, "total_cost_usd": 0.0, "result": "```json\n" + json.dumps(OUT[agent]) + "\n```"}))
