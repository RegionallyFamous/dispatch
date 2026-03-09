# Troubleshooting Error Catalog

This catalog is the source for quick triage in wiki troubleshooting pages.

## First 60 seconds triage

1. Run `wp telex health`.
2. Confirm site is connected (`Authentication: Connected`).
3. Check circuit breaker status.
4. Confirm file modifications are enabled.
5. Retry action once after cache warm (`wp telex cache warm`).

## Error catalog

| Symptom | Likely cause | Fast fix | Escalation |
|---|---|---|---|
| `Not connected. Run: wp telex connect` | OAuth token missing/expired | Run `wp telex connect` and re-authorize | If flow repeatedly fails, capture output and open issue |
| Install/update action fails immediately | `DISALLOW_FILE_MODS` or environment prevents file writes | Enable file modifications for plugin updates and retry | Verify host policy and filesystem permissions |
| `Could not initialise Telex client.` | Invalid token state or configuration issue | Disconnect and reconnect (`wp telex disconnect`, then `wp telex connect`) | Inspect logs and open issue with environment details |
| `API Reachability: Unreachable` in health | Telex API unreachable or network policy blocks outbound requests | Retry later, verify outbound HTTPS connectivity | If persistent, open issue with host/network context |
| Circuit breaker shows `OPEN` | Repeated API failures triggered protective backoff | Wait for recovery, or run `wp telex circuit reset` after confirming API is healthy | Escalate with health output and recent failure context |
| Snapshot restore reports warnings | One or more project installs failed during restore | Review warning lines per project and retry failed IDs manually | Open issue with snapshot ID and failing project IDs |
| Config import rejected as invalid | JSON file malformed or not Dispatch config format | Re-export from known-good site and retry import | Validate file integrity in source control / transfer pipeline |

## Recovery-first guidance

- Prefer snapshots for full-state recovery over ad-hoc command retries.
- Use pinning on mission-critical projects before broad updates.
- Run high-risk updates in staging before production.
