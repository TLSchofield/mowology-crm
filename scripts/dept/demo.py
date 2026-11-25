"""Controlled end-to-end commercial workflow on a SYNTHETIC prospect, run against a stand-in CLI (no model, no network, nobody contacted).
Used by tests and by `ada demo-commercial` (which runs in its own folder, never your real queue). It proves the machinery; it proves nothing about real markets."""
import json, os, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
STANDIN = os.path.join(HERE, "demo", "standin-claude.py")


def run(ada, db, say=print):
    C, AG = ada.C, ada.AG
    os.environ["ADA_CLAUDE_BIN"] = STANDIN
    ada.seed_permissions(db)
    C.add_capability(db, "Quotes: build, send and track quotes", "demo fixture", ["app/Modules/Quotes"], "demo fixture only")
    C.verify_capability(db, "Quotes: build, send and track quotes", "guardian", "DEMO FIXTURE verification — in real use only Guardian or the owner verifies", tty=False)
    say("0. fixture: one capability marked verified (demo only). Real register: all unverified until you or Guardian verify.")
    ids = {}
    chain = [("compass", "Compass: pick the target segment", []), ("hunter", "Hunter: research one prospect", ["compass"]), ("scribe", "Scribe: draft an outreach email", ["hunter"]),
             ("apollo", "Apollo: evaluate the opportunity and eligibility", ["scribe"]), ("closer", "Closer: discovery and demo plan", ["apollo"]), ("atlas", "Atlas: capture verified lessons", ["closer"])]
    for ag, title, deps in chain:
        ids[ag] = ada.add_task(db, "demo:%s:%s" % (ag, os.environ.get("DEMO_RUN", "1")), title, "commercial", ag, payload={"synthetic": True, "input": {}}, depends_on=[ids[d] for d in deps])[0]
    res = ada.tick(db, max_runs=10)
    for r in res:
        say("   %s" % (r,))
    opp = AG.dependency_results(db, ada.get_task(db, ids["apollo"]))["opp"]
    say("1-4. segment -> prospect -> draft -> Apollo evaluated:", opp)
    # 5. eligibility and approval requirements are checked: the gate must refuse
    for target in ("contact_eligibility_verified", "contacted"):
        try:
            C.move_stage(db, opp, target, "apollo", "try to skip the owner")
            say("   !! gate FAILED: reached", target)
        except C.Refused as e:
            say("5. gate refused %s: %s" % (target, e))
    say("6-7. recorded in the single pipeline at stage:", C.get_opp(db, opp)["stage"], "| next action:", C.get_opp(db, opp)["next_action"])
    say("8. Atlas lessons (draft, not authoritative):", [dict(r)["statement"] for r in db.execute("SELECT statement FROM knowledge WHERE created_by='atlas'")])
    say("10. STOP: no message was sent; sendable drafts =", db.execute("SELECT COUNT(*) c FROM communications WHERE status IN ('sent','approved')").fetchone()["c"])
    return {"ids": ids, "opp": opp}


if __name__ == "__main__" and "--cli" in sys.argv:
    import importlib.util, shutil
    home = os.environ["DEPT_HOME"]
    shutil.rmtree(home, ignore_errors=True)
    spec = importlib.util.spec_from_file_location("ada", os.path.join(HERE, "ada.py"))
    ada = importlib.util.module_from_spec(spec); spec.loader.exec_module(ada)
    db = ada.connect()
    run(ada, db)
    print("briefing:", ada.write_briefing(db, __import__("datetime").date.today().isoformat()))
