-- Migration 942: Compound performance indexes on hot query paths
-- All single-column indexes on these tables already exist.
-- These compound indexes cover multi-column WHERE + ORDER BY patterns
-- used in list views, activity feeds, and schedule queries.

-- communication_log: contact activity feed (ORDER BY created_at)
CREATE INDEX idx_comm_contact_created ON communication_log(contact_id, created_at);

-- activity_log: user activity filtered by date (dashboard, audit log)
CREATE INDEX idx_activity_user_created ON activity_log(user_id, created_at);

-- invoices: list all invoices for a company sorted by due date
CREATE INDEX idx_invoice_company_status ON invoices(company_id, status, due_date);

-- job_visits: completed visits by crew on a given date (schedule + reporting)
CREATE INDEX idx_visit_crew_date_status ON job_visits(assigned_crew_id, scheduled_date, status);

-- service_orders: what is scheduled this week
CREATE INDEX idx_svcorder_date_status ON service_orders(scheduled_date, status);

-- client_feedback: job-based lookup with date sort
CREATE INDEX idx_feedback_job_created ON client_feedback(job_id, created_at);
