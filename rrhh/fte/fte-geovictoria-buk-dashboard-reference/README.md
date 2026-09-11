# FTE GeoVictoria + Buk Dashboard Reference

This folder contains the pieces most useful for recreating the FTE portions of our line attendance dashboard.

The request was specifically to ignore our internal shift estimations, internally defined workers, and CECO-to-supervisor correlation. The files are still copied from our current app, so some internal concepts remain in the source. Treat those as examples or delete them during extraction.

## What to Start With

- `ui/src/pages/admin/dashboards/dashboard_line_attendance_throughput.tsx`
  - React dashboard that already contains the FTE UI.
  - The relevant FTE tab is driven by the attendance-only endpoint and the `AttendancePayload`, `CostCenterPerson`, and `PersonShiftDay` types.
  - The correlation/output portions can be removed if the target dashboard only needs FTE.

- `backend/app/api/routes/line_attendance_throughput.py`
  - Main aggregation endpoint.
  - The most relevant endpoint for a Buk/GeoVictoria-only FTE rebuild is `/attendance`.
  - The broader `/` endpoint includes our internal workers, task participation, panel completion, and supervisor/CECO mapping logic.

- `backend/app/services/buk_people.py`
  - Fetches Buk people, extracts identifiers and cost centers, normalizes RUT/person identifiers, and builds lookup indexes.

- `backend/app/api/routes/geovictoria.py`
  - GeoVictoria auth, token caching, request pacing, active worker list, and AttendanceBook request helpers.

- `backend/app/schemas/line_attendance_throughput.py`
  - Pydantic response models for the dashboard and attendance/FTE data.

- `backend/app/models/geovictoria_attendance_cache.py`
- `backend/alembic/versions/0037_geovictoria_attendance_cache.py`
  - Optional but useful if the rebuild will cache GeoVictoria attendance calls.

## Minimal Data Flow for FTE

1. Fetch people from Buk.
2. Normalize each person identifier with `normalize_person_identifier`.
3. Group/filter people by Buk cost center.
4. Query GeoVictoria `AttendanceBook` for those normalized identifiers and date range.
5. For each person/day, derive:
   - `present`
   - `first_entry`
   - `last_exit`
   - net hours
   - extra hours
   - FTE days
6. Return data shaped like `LineAttendanceThroughputAttendanceResponse`.
7. Render the FTE tab in the React dashboard.

## Environment Variables

The backend expects these settings:

```env
GEOVICTORIA_BASE_URL=https://customerapi.geovictoria.com/api/v1
GEOVICTORIA_API_USER=...
GEOVICTORIA_API_PASSWORD=...
GEOVICTORIA_TOKEN_TTL_SECONDS=1200
GEOVICTORIA_ATTENDANCE_MIN_INTERVAL_SECONDS=0.35
GEOVICTORIA_429_RETRY_SECONDS=1.5
GEOVICTORIA_429_MAX_RETRIES=2

BUK_BASE_URL=https://your-company.buk.cl
BUK_API_TOKEN=...
BUK_COUNTRY=chile
BUK_PEOPLE_CACHE_TTL_SECONDS=300
```

## Parts to Ignore for a Buk/GeoVictoria-Only Rebuild

- Internal workers and `Worker`/`WorkerSupervisor` mapping.
- Task participation and panel/module throughput metrics.
- Shift estimate routes and scheduler.
- CECO-to-supervisor fallback/correlation UI.
- QC and production station dashboard code in `PanelLineSupervisorView.tsx`.

`PanelLineSupervisorView.tsx` was not copied because it is mostly a production/QC supervisor workflow. It is useful as a visual/style reference, but not as a technical source for FTE from Buk + GeoVictoria.

## Integration Notes

- Register `line_attendance_throughput.py` and `geovictoria.py` as FastAPI routers in your backend app.
- The React component uses `VITE_API_BASE_URL` and calls `/api/line-attendance-throughput` plus `/api/line-attendance-throughput/attendance`.
- If you keep only FTE, simplify the frontend to the FTE tab and the attendance endpoint.
- Use server-side caching or persisted attendance cache if the dashboard will be opened often; GeoVictoria can rate-limit attendance calls.
