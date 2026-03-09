# Troubleshooting

## First 60 seconds

1. Run `wp telex health`.
2. Confirm `Authentication` is connected.
3. Check circuit breaker state.
4. Confirm file modifications are enabled.
5. Warm cache and retry:

```bash
wp telex cache warm
```

## Frequent issues

### Not connected

`Not connected. Run: wp telex connect`

Fix:

```bash
wp telex connect
```

### Circuit breaker is open

If Telex API has recovered:

```bash
wp telex circuit reset
```

### Install/update still failing

- Retry by project ID with CLI to get explicit output.
- If change window is risky, restore from snapshot.

## Recovery path

```bash
wp telex snapshot list
wp telex snapshot restore <snapshot-id>
```

## Escalation

If issue persists, include:

- `wp telex health` output
- failing command output
- environment context (single site vs multisite, host restrictions)
