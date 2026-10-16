# Changelog

## 1.0.1 (unreleased)

- Fix pull pagination that could skip records across models or when records share a timestamp.
- Return complete timestamp groups; `limit` is a target and responses may exceed it.
- Use the later `updated_at` / `deleted_at` value when ordering soft-deleted records, retaining fractional timestamp precision.
- Allow `tether/core` dependencies from either `^0` or `^1.0`.

Update the server package to apply the fix. Client applications need no update or migration. Previously skipped records are not automatically replayed.
