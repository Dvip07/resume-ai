import { Route, Routes } from 'react-router-dom'

import { LOGIN_ROUTE, REGISTER_ROUTE } from './auth/authContext'
import { GuestRoute } from './auth/GuestRoute'
import { ProtectedRoute } from './auth/ProtectedRoute'
import { AppLayout } from './layout/AppLayout'
import { ApplicationDetailPage } from './routes/ApplicationDetailPage'
import { ApplicationsPage } from './routes/ApplicationsPage'
import { AutomationSettingsPage } from './routes/AutomationSettingsPage'
import { DashboardPage } from './routes/DashboardPage'
import { JobDetailPage } from './routes/JobDetailPage'
import { JobsPage } from './routes/JobsPage'
import { LoginPage } from './routes/LoginPage'
import { NotFoundPage } from './routes/NotFoundPage'
import { ProfilePage } from './routes/ProfilePage'
import { RegisterPage } from './routes/RegisterPage'
import { ResumeDetailPage } from './routes/ResumeDetailPage'
import { ResumesPage } from './routes/ResumesPage'
import {
  APPLICATION_DETAIL_ROUTE,
  APPLICATIONS_ROUTE,
  AUTOMATION_SETTINGS_ROUTE,
  DASHBOARD_ROUTE,
  JOB_DETAIL_ROUTE,
  JOBS_ROUTE,
  PROFILE_ROUTE,
  RESUME_DETAIL_ROUTE,
  RESUMES_ROUTE,
} from './routes/paths'

/**
 * Route table (Requirement 1.4). `/login` and `/register` are guest-only;
 * everything else nests under `ProtectedRoute` — which redirects to /login and
 * remembers the intended destination (Requirement 1.6) — then under
 * `AppLayout`, the shared authenticated shell.
 *
 * The resumes, jobs, applications, profile, and automation-settings screens are
 * real (a list plus a detail route where the API has both); the dashboard is
 * the shell with the pipeline-health widget.
 */
function App() {
  return (
    <Routes>
      <Route element={<GuestRoute />}>
        <Route path={LOGIN_ROUTE} element={<LoginPage />} />
        <Route path={REGISTER_ROUTE} element={<RegisterPage />} />
      </Route>

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path={DASHBOARD_ROUTE} element={<DashboardPage />} />
          <Route path={RESUMES_ROUTE} element={<ResumesPage />} />
          <Route path={RESUME_DETAIL_ROUTE} element={<ResumeDetailPage />} />
          <Route path={JOBS_ROUTE} element={<JobsPage />} />
          <Route path={JOB_DETAIL_ROUTE} element={<JobDetailPage />} />
          <Route path={APPLICATIONS_ROUTE} element={<ApplicationsPage />} />
          <Route path={APPLICATION_DETAIL_ROUTE} element={<ApplicationDetailPage />} />
          <Route path={PROFILE_ROUTE} element={<ProfilePage />} />
          <Route path={AUTOMATION_SETTINGS_ROUTE} element={<AutomationSettingsPage />} />
        </Route>
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}

export default App
