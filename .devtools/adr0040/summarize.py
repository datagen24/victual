#!/usr/bin/env python3
"""Print the headline numbers of a race-probe.php evidence file, without the per-iteration rows."""
import json
import sys

data = json.load(open(sys.argv[1]))
print("postgres", data["environment"]["postgres"], "| seed", data["master_seed"], "| iterations per scenario", data["iterations_per_scenario"])
for name, s in data["scenarios"].items():
    extras = {k: v for k, v in s.items() if k.startswith("final") or k.startswith("iterations_with")}
    print(f"\n{name}: analysed={s['iterations_analysed']} ties={s['commit_timestamp_ties']} hung={s['hung_iterations']}")
    print("  deadlock delta:", s["pg_stat_database_deadlocks_delta"], "| errors by SQLSTATE:", s["errors_by_sqlstate"], "| violations:", s["violations"], extras or "")
    for key, n in s["outcome_table"].items():
        print(f"  {n:5d}  {key}")
