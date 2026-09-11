# Route and Config Excerpts

These are the small wiring pieces from our app that are useful when adapting the copied files.

## Backend Settings

From `backend/app/core/config.py`:

```python
geovictoria_base_url: str = os.getenv(
    "GEOVICTORIA_BASE_URL",
    "https://customerapi.geovictoria.com/api/v1",
)
geovictoria_api_user: str | None = os.getenv("GEOVICTORIA_API_USER") or os.getenv(
    "GEOVICTORIA_USER"
)
geovictoria_api_password: str | None = os.getenv("GEOVICTORIA_API_PASSWORD")
geovictoria_token_ttl_seconds: int = int(
    os.getenv("GEOVICTORIA_TOKEN_TTL_SECONDS", "1200")
)
geovictoria_attendance_min_interval_seconds: float = float(
    os.getenv("GEOVICTORIA_ATTENDANCE_MIN_INTERVAL_SECONDS", "0.35")
)
geovictoria_429_retry_seconds: float = float(
    os.getenv("GEOVICTORIA_429_RETRY_SECONDS", "1.5")
)
geovictoria_429_max_retries: int = int(
    os.getenv("GEOVICTORIA_429_MAX_RETRIES", "2")
)
buk_base_url: str = os.getenv("BUK_BASE_URL", "https://grupopatagual.buk.cl")
buk_api_token: str | None = os.getenv("BUK_API_TOKEN") or os.getenv("BUK_TOKEN")
buk_country: str = os.getenv("BUK_COUNTRY", "chile")
buk_people_cache_ttl_seconds: int = int(
    os.getenv("BUK_PEOPLE_CACHE_TTL_SECONDS", "300")
)
```

## Frontend Route

From `ui/src/App.tsx`:

```tsx
import DashboardLineAttendanceThroughput from './pages/admin/dashboards/dashboard_line_attendance_throughput';

<Route
  path="dashboards/line-attendance-throughput"
  element={
    <DashboardVisibilityGuard dashboardId="line-attendance-throughput">
      <DashboardLineAttendanceThroughput />
    </DashboardVisibilityGuard>
  }
/>
```

For a standalone app, this can be just:

```tsx
<Route path="/fte" element={<DashboardLineAttendanceThroughput />} />
```

## Backend Router Registration

Register the copied route modules under your `/api` prefix. The exact syntax depends on your FastAPI app layout, but ours uses the same shape as:

```python
from app.api.routes import geovictoria, line_attendance_throughput

app.include_router(geovictoria.router, prefix="/api/geovictoria", tags=["geovictoria"])
app.include_router(
    line_attendance_throughput.router,
    prefix="/api/line-attendance-throughput",
    tags=["line-attendance-throughput"],
)
```
