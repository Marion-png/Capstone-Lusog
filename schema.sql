--
-- PostgreSQL database dump
--

\restrict 1lK0IMEgYF1YeB0ovzrCCdVkQKaxz1e1VSboTYmSKypu4nOCN4du9a8NGn7JWm7

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: account_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.account_requests (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    username character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role character varying(255) NOT NULL,
    institution_id bigint,
    school_name character varying(255),
    assigned_grade_level character varying(255),
    assigned_section character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    decided_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.account_requests OWNER TO postgres;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    username character varying(255) NOT NULL,
    password_hash character varying(255),
    role character varying(255) NOT NULL,
    institution_id bigint,
    school_name character varying(255),
    assigned_grade_level character varying(255),
    assigned_section character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.accounts OWNER TO postgres;

--
-- Name: accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.accounts_id_seq OWNER TO postgres;

--
-- Name: accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.accounts_id_seq OWNED BY public.accounts.id;


--
-- Name: announcements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.announcements (
    id bigint NOT NULL,
    institution_id bigint,
    title character varying(255) NOT NULL,
    body text NOT NULL,
    posted_by_name character varying(255) NOT NULL,
    posted_by_role character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.announcements OWNER TO postgres;

--
-- Name: announcements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.announcements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.announcements_id_seq OWNER TO postgres;

--
-- Name: announcements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.announcements_id_seq OWNED BY public.announcements.id;


--
-- Name: attendance_imports; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.attendance_imports (
    id bigint NOT NULL,
    institution_id bigint,
    school_year character varying(255) NOT NULL,
    uploaded_by_name text,
    original_filename character varying(255),
    stored_path character varying(255),
    sessions_count integer DEFAULT 0 NOT NULL,
    matched_count integer DEFAULT 0 NOT NULL,
    unmatched_count integer DEFAULT 0 NOT NULL,
    row_errors text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.attendance_imports OWNER TO postgres;

--
-- Name: attendance_imports_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.attendance_imports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendance_imports_id_seq OWNER TO postgres;

--
-- Name: attendance_imports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.attendance_imports_id_seq OWNED BY public.attendance_imports.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.audit_logs (
    id bigint NOT NULL,
    actor_name character varying(255),
    actor_username character varying(255),
    actor_role character varying(255),
    institution_id bigint,
    action character varying(255) NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    description character varying(255),
    details text,
    http_method character varying(10),
    url character varying(2048),
    route_name character varying(255),
    ip_address character varying(45),
    created_at timestamp(0) without time zone
);


ALTER TABLE public.audit_logs OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_id_seq OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO postgres;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO postgres;

--
-- Name: clinic_notes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.clinic_notes (
    id bigint NOT NULL,
    institution_id bigint,
    student_lrn character varying(255) NOT NULL,
    school_year character varying(255) NOT NULL,
    note text NOT NULL,
    author_name text NOT NULL,
    follow_up_date date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.clinic_notes OWNER TO postgres;

--
-- Name: clinic_notes_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.clinic_notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.clinic_notes_id_seq OWNER TO postgres;

--
-- Name: clinic_notes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.clinic_notes_id_seq OWNED BY public.clinic_notes.id;


--
-- Name: conditions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.conditions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    category character varying(255),
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.conditions OWNER TO postgres;

--
-- Name: conditions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.conditions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.conditions_id_seq OWNER TO postgres;

--
-- Name: conditions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.conditions_id_seq OWNED BY public.conditions.id;


--
-- Name: consultations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.consultations (
    id bigint NOT NULL,
    consulted_at timestamp(0) without time zone NOT NULL,
    student_name text NOT NULL,
    grade_section text NOT NULL,
    condition text NOT NULL,
    treatment_given text,
    status character varying(20) DEFAULT 'treated'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    condition_id bigint,
    institution_id bigint
);


ALTER TABLE public.consultations OWNER TO postgres;

--
-- Name: consultations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.consultations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.consultations_id_seq OWNER TO postgres;

--
-- Name: consultations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.consultations_id_seq OWNED BY public.consultations.id;


--
-- Name: deworming_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.deworming_requests (
    id character varying(255) NOT NULL,
    submitted_at timestamp(0) without time zone,
    submitted_by character varying(255),
    submitted_by_role character varying(255),
    campaign character varying(20) NOT NULL,
    total_students integer NOT NULL,
    consenting_students integer NOT NULL,
    tablets_requested integer NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    released_date date,
    grade_level character varying(50),
    section character varying(100),
    nurse_comment text,
    commented_at timestamp(0) without time zone,
    reviewed_at timestamp(0) without time zone,
    reviewed_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    institution_id bigint
);


ALTER TABLE public.deworming_requests OWNER TO postgres;

--
-- Name: events; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.events (
    id bigint NOT NULL,
    institution_id bigint,
    title character varying(255) NOT NULL,
    description text,
    event_date date NOT NULL,
    category character varying(255) DEFAULT 'program'::character varying NOT NULL,
    created_by_name character varying(255) NOT NULL,
    created_by_role character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.events OWNER TO postgres;

--
-- Name: events_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.events_id_seq OWNER TO postgres;

--
-- Name: events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.events_id_seq OWNED BY public.events.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: feeding_attendances; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.feeding_attendances (
    id bigint NOT NULL,
    student_health_record_id bigint NOT NULL,
    session_date date NOT NULL,
    is_present boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.feeding_attendances OWNER TO postgres;

--
-- Name: feeding_attendances_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.feeding_attendances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.feeding_attendances_id_seq OWNER TO postgres;

--
-- Name: feeding_attendances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.feeding_attendances_id_seq OWNED BY public.feeding_attendances.id;


--
-- Name: health_assessments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.health_assessments (
    id bigint NOT NULL,
    student_health_record_id bigint NOT NULL,
    school_year character varying(20) NOT NULL,
    date_of_assessment date,
    assessed_by character varying(255),
    med_asthma text DEFAULT '0'::text NOT NULL,
    med_diabetes text DEFAULT '0'::text NOT NULL,
    med_seizure_disorder text DEFAULT '0'::text NOT NULL,
    med_frequent_infections text DEFAULT '0'::text NOT NULL,
    med_current_medications text,
    med_allergies text DEFAULT '0'::text NOT NULL,
    med_allergies_detail text,
    med_heart_condition text DEFAULT '0'::text NOT NULL,
    med_tuberculosis text DEFAULT '0'::text NOT NULL,
    med_hospitalization_surgery text DEFAULT '0'::text NOT NULL,
    med_hospitalization_detail text,
    med_other_conditions text,
    fam_hypertension text DEFAULT '0'::text NOT NULL,
    fam_diabetes text DEFAULT '0'::text NOT NULL,
    fam_heart_disease text DEFAULT '0'::text NOT NULL,
    fam_cancer text DEFAULT '0'::text NOT NULL,
    fam_mental_health text DEFAULT '0'::text NOT NULL,
    fam_genetic_hereditary text,
    appearance_consciousness text,
    appearance_consciousness_other text,
    appearance_posture_gait text,
    appearance_posture_detail text,
    appearance_hygiene text,
    vital_height_cm text,
    vital_weight_kg text,
    vital_bmi text,
    vital_temperature_c text,
    vital_pulse_rate text,
    vital_blood_pressure text,
    body_systems text,
    vision_right_eye text,
    vision_left_eye text,
    vision_result text,
    hearing_result text,
    teeth_condition text,
    last_dental_visit text,
    dental_referral text DEFAULT '0'::text NOT NULL,
    immunization_status text,
    missing_needed_vaccines text,
    immunization_date_reviewed date,
    summary_of_findings text,
    recommendations text,
    examiner_signature text,
    submitted_by_name character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.health_assessments OWNER TO postgres;

--
-- Name: health_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.health_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.health_assessments_id_seq OWNER TO postgres;

--
-- Name: health_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.health_assessments_id_seq OWNED BY public.health_assessments.id;


--
-- Name: health_consent_forms; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.health_consent_forms (
    id bigint NOT NULL,
    token character varying(255),
    school_year character varying(255) NOT NULL,
    institution_id bigint,
    division character varying(255) NOT NULL,
    school_name character varying(255) NOT NULL,
    school_address character varying(255) NOT NULL,
    student_lrn character varying(255) NOT NULL,
    student_name text NOT NULL,
    student_address text,
    grade_level character varying(255),
    section character varying(255),
    parent_guardian_name text,
    services text,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    consent_choice text,
    consent_exceptions text,
    refusal_reason text,
    allergy_food text,
    allergy_medicine text,
    prev_immunization text,
    other_illness text,
    signature text,
    sent_at timestamp(0) without time zone,
    signed_at timestamp(0) without time zone,
    reviewed_at timestamp(0) without time zone,
    created_by_name character varying(255),
    adviser_unread boolean DEFAULT false NOT NULL,
    audit text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.health_consent_forms OWNER TO postgres;

--
-- Name: health_consent_forms_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.health_consent_forms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.health_consent_forms_id_seq OWNER TO postgres;

--
-- Name: health_consent_forms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.health_consent_forms_id_seq OWNED BY public.health_consent_forms.id;


--
-- Name: institution_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.institution_requests (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    address character varying(255),
    division character varying(255),
    contact_person character varying(255) NOT NULL,
    contact_email character varying(255) NOT NULL,
    contact_number character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    decline_reason text,
    institution_id bigint,
    reviewed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.institution_requests OWNER TO postgres;

--
-- Name: institutions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.institutions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    address character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    database_name character varying(255),
    provisioned_at timestamp(0) without time zone
);


ALTER TABLE public.institutions OWNER TO postgres;

--
-- Name: institutions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.institutions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.institutions_id_seq OWNER TO postgres;

--
-- Name: institutions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.institutions_id_seq OWNED BY public.institutions.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO postgres;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: medical_certificates; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.medical_certificates (
    id bigint NOT NULL,
    student_health_condition_id bigint,
    file_path character varying(255) NOT NULL,
    file_original_name text NOT NULL,
    doctor_clinic text,
    diagnosis_date date,
    uploaded_by_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    student_lrn character varying(50),
    institution_id bigint,
    file_size bigint,
    uploaded_by_role character varying(40)
);


ALTER TABLE public.medical_certificates OWNER TO postgres;

--
-- Name: medical_certificates_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.medical_certificates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.medical_certificates_id_seq OWNER TO postgres;

--
-- Name: medical_certificates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.medical_certificates_id_seq OWNED BY public.medical_certificates.id;


--
-- Name: medicines; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.medicines (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    stock_quantity integer DEFAULT 0 NOT NULL,
    minimum_threshold integer DEFAULT 20 NOT NULL,
    unit character varying(20) DEFAULT 'pcs'::character varying NOT NULL,
    notes character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    institution_id bigint
);


ALTER TABLE public.medicines OWNER TO postgres;

--
-- Name: medicines_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.medicines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.medicines_id_seq OWNER TO postgres;

--
-- Name: medicines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.medicines_id_seq OWNED BY public.medicines.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: parental_consent_forms; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.parental_consent_forms (
    id bigint NOT NULL,
    student_health_record_id bigint NOT NULL,
    program_type character varying(50) DEFAULT 'Deworming'::character varying NOT NULL,
    school_year character varying(9) NOT NULL,
    file_path character varying(255),
    file_original_name text,
    uploaded_by_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    consent_type text DEFAULT 'full'::character varying NOT NULL,
    partial_exception text,
    refused_reason text,
    allergy_food text DEFAULT '0'::text NOT NULL,
    allergy_food_detail text,
    allergy_medicine text DEFAULT '0'::text NOT NULL,
    allergy_medicine_detail text,
    prev_immunization text DEFAULT '0'::text NOT NULL,
    prev_immunization_detail text,
    has_other_illness text DEFAULT '0'::text NOT NULL,
    other_illness_detail text,
    medical_cert_attached text DEFAULT '0'::text NOT NULL,
    med_cert_path character varying(255),
    med_cert_original_name text,
    notes text
);


ALTER TABLE public.parental_consent_forms OWNER TO postgres;

--
-- Name: parental_consent_forms_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.parental_consent_forms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.parental_consent_forms_id_seq OWNER TO postgres;

--
-- Name: parental_consent_forms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.parental_consent_forms_id_seq OWNED BY public.parental_consent_forms.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO postgres;

--
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- Name: student_health_conditions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.student_health_conditions (
    id bigint NOT NULL,
    condition_name text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    student_lrn character varying(255),
    institution_id bigint
);


ALTER TABLE public.student_health_conditions OWNER TO postgres;

--
-- Name: student_health_conditions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.student_health_conditions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.student_health_conditions_id_seq OWNER TO postgres;

--
-- Name: student_health_conditions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.student_health_conditions_id_seq OWNED BY public.student_health_conditions.id;


--
-- Name: student_health_records; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.student_health_records (
    id bigint NOT NULL,
    student_name text NOT NULL,
    student_id character varying(255) NOT NULL,
    section character varying(255) NOT NULL,
    weight text NOT NULL,
    bmi_value text NOT NULL,
    nutritional_status text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    baseline_age text,
    baseline_height_cm text,
    baseline_weight_kg text,
    baseline_bmi_value text,
    baseline_nutritional_status text,
    baseline_recorded_at date,
    endline_age text,
    endline_height_cm text,
    endline_weight_kg text,
    endline_bmi_value text,
    endline_nutritional_status text,
    endline_recorded_at date,
    attendance_sessions_count smallint DEFAULT '0'::smallint NOT NULL,
    is_at_risk boolean DEFAULT false NOT NULL,
    school_name character varying(255),
    institution_id bigint,
    examination text,
    attendance_by_month text,
    student_details text,
    school_year character varying(255) NOT NULL
);


ALTER TABLE public.student_health_records OWNER TO postgres;

--
-- Name: student_health_records_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.student_health_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.student_health_records_id_seq OWNER TO postgres;

--
-- Name: student_health_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.student_health_records_id_seq OWNED BY public.student_health_records.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    institution_id bigint
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: accounts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts ALTER COLUMN id SET DEFAULT nextval('public.accounts_id_seq'::regclass);


--
-- Name: announcements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcements ALTER COLUMN id SET DEFAULT nextval('public.announcements_id_seq'::regclass);


--
-- Name: attendance_imports id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_imports ALTER COLUMN id SET DEFAULT nextval('public.attendance_imports_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: clinic_notes id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.clinic_notes ALTER COLUMN id SET DEFAULT nextval('public.clinic_notes_id_seq'::regclass);


--
-- Name: conditions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.conditions ALTER COLUMN id SET DEFAULT nextval('public.conditions_id_seq'::regclass);


--
-- Name: consultations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.consultations ALTER COLUMN id SET DEFAULT nextval('public.consultations_id_seq'::regclass);


--
-- Name: events id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.events ALTER COLUMN id SET DEFAULT nextval('public.events_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: feeding_attendances id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.feeding_attendances ALTER COLUMN id SET DEFAULT nextval('public.feeding_attendances_id_seq'::regclass);


--
-- Name: health_assessments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_assessments ALTER COLUMN id SET DEFAULT nextval('public.health_assessments_id_seq'::regclass);


--
-- Name: health_consent_forms id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_consent_forms ALTER COLUMN id SET DEFAULT nextval('public.health_consent_forms_id_seq'::regclass);


--
-- Name: institutions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.institutions ALTER COLUMN id SET DEFAULT nextval('public.institutions_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: medical_certificates id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medical_certificates ALTER COLUMN id SET DEFAULT nextval('public.medical_certificates_id_seq'::regclass);


--
-- Name: medicines id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medicines ALTER COLUMN id SET DEFAULT nextval('public.medicines_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: parental_consent_forms id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.parental_consent_forms ALTER COLUMN id SET DEFAULT nextval('public.parental_consent_forms_id_seq'::regclass);


--
-- Name: student_health_conditions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_health_conditions ALTER COLUMN id SET DEFAULT nextval('public.student_health_conditions_id_seq'::regclass);


--
-- Name: student_health_records id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_health_records ALTER COLUMN id SET DEFAULT nextval('public.student_health_records_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: account_requests account_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_requests
    ADD CONSTRAINT account_requests_pkey PRIMARY KEY (id);


--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (id);


--
-- Name: accounts accounts_username_institution_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_username_institution_unique UNIQUE (username, institution_id);


--
-- Name: announcements announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcements
    ADD CONSTRAINT announcements_pkey PRIMARY KEY (id);


--
-- Name: attendance_imports attendance_imports_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_imports
    ADD CONSTRAINT attendance_imports_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: clinic_notes clinic_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.clinic_notes
    ADD CONSTRAINT clinic_notes_pkey PRIMARY KEY (id);


--
-- Name: conditions conditions_name_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.conditions
    ADD CONSTRAINT conditions_name_unique UNIQUE (name);


--
-- Name: conditions conditions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.conditions
    ADD CONSTRAINT conditions_pkey PRIMARY KEY (id);


--
-- Name: consultations consultations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.consultations
    ADD CONSTRAINT consultations_pkey PRIMARY KEY (id);


--
-- Name: deworming_requests deworming_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deworming_requests
    ADD CONSTRAINT deworming_requests_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: feeding_attendances feeding_attendance_unique_student_session; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.feeding_attendances
    ADD CONSTRAINT feeding_attendance_unique_student_session UNIQUE (student_health_record_id, session_date);


--
-- Name: feeding_attendances feeding_attendances_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.feeding_attendances
    ADD CONSTRAINT feeding_attendances_pkey PRIMARY KEY (id);


--
-- Name: health_assessments health_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_assessments
    ADD CONSTRAINT health_assessments_pkey PRIMARY KEY (id);


--
-- Name: health_consent_forms health_consent_forms_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_consent_forms
    ADD CONSTRAINT health_consent_forms_pkey PRIMARY KEY (id);


--
-- Name: health_consent_forms health_consent_forms_student_lrn_school_year_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_consent_forms
    ADD CONSTRAINT health_consent_forms_student_lrn_school_year_unique UNIQUE (student_lrn, school_year);


--
-- Name: health_consent_forms health_consent_forms_token_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_consent_forms
    ADD CONSTRAINT health_consent_forms_token_unique UNIQUE (token);


--
-- Name: institution_requests institution_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.institution_requests
    ADD CONSTRAINT institution_requests_pkey PRIMARY KEY (id);


--
-- Name: institutions institutions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.institutions
    ADD CONSTRAINT institutions_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: medical_certificates medical_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medical_certificates
    ADD CONSTRAINT medical_certificates_pkey PRIMARY KEY (id);


--
-- Name: medicines medicines_name_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medicines
    ADD CONSTRAINT medicines_name_unique UNIQUE (name);


--
-- Name: medicines medicines_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medicines
    ADD CONSTRAINT medicines_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: parental_consent_forms parental_consent_forms_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.parental_consent_forms
    ADD CONSTRAINT parental_consent_forms_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: student_health_conditions student_health_conditions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_health_conditions
    ADD CONSTRAINT student_health_conditions_pkey PRIMARY KEY (id);


--
-- Name: student_health_records student_health_records_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_health_records
    ADD CONSTRAINT student_health_records_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: announcements_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX announcements_institution_id_index ON public.announcements USING btree (institution_id);


--
-- Name: attendance_imports_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX attendance_imports_institution_id_index ON public.attendance_imports USING btree (institution_id);


--
-- Name: attendance_imports_school_year_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX attendance_imports_school_year_index ON public.attendance_imports USING btree (school_year);


--
-- Name: attendance_imports_scope_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX attendance_imports_scope_idx ON public.attendance_imports USING btree (institution_id, school_year);


--
-- Name: audit_logs_action_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_action_index ON public.audit_logs USING btree (action);


--
-- Name: audit_logs_actor_role_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_actor_role_index ON public.audit_logs USING btree (actor_role);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: audit_logs_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_institution_id_index ON public.audit_logs USING btree (institution_id);


--
-- Name: audit_logs_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_subject_type_subject_id_index ON public.audit_logs USING btree (subject_type, subject_id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: clinic_notes_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX clinic_notes_institution_id_index ON public.clinic_notes USING btree (institution_id);


--
-- Name: clinic_notes_school_year_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX clinic_notes_school_year_index ON public.clinic_notes USING btree (school_year);


--
-- Name: clinic_notes_student_lrn_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX clinic_notes_student_lrn_index ON public.clinic_notes USING btree (student_lrn);


--
-- Name: clinic_notes_student_lrn_school_year_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX clinic_notes_student_lrn_school_year_index ON public.clinic_notes USING btree (student_lrn, school_year);


--
-- Name: deworming_requests_grade_level_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deworming_requests_grade_level_index ON public.deworming_requests USING btree (grade_level);


--
-- Name: deworming_requests_section_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deworming_requests_section_index ON public.deworming_requests USING btree (section);


--
-- Name: deworming_requests_submitted_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deworming_requests_submitted_at_index ON public.deworming_requests USING btree (submitted_at);


--
-- Name: events_event_date_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX events_event_date_index ON public.events USING btree (event_date);


--
-- Name: events_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX events_institution_id_index ON public.events USING btree (institution_id);


--
-- Name: health_consent_forms_institution_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX health_consent_forms_institution_id_index ON public.health_consent_forms USING btree (institution_id);


--
-- Name: health_consent_forms_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX health_consent_forms_status_index ON public.health_consent_forms USING btree (status);


--
-- Name: health_consent_forms_student_lrn_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX health_consent_forms_student_lrn_index ON public.health_consent_forms USING btree (student_lrn);


--
-- Name: institution_requests_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX institution_requests_status_index ON public.institution_requests USING btree (status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: mc_student_institution_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX mc_student_institution_idx ON public.medical_certificates USING btree (student_lrn, institution_id);


--
-- Name: parental_consent_forms_student_health_record_id_program_type_sc; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX parental_consent_forms_student_health_record_id_program_type_sc ON public.parental_consent_forms USING btree (student_health_record_id, program_type, school_year);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: shc_student_institution_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX shc_student_institution_idx ON public.student_health_conditions USING btree (student_lrn, institution_id);


--
-- Name: shr_student_institution_year_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX shr_student_institution_year_idx ON public.student_health_records USING btree (student_id, institution_id, school_year);


--
-- Name: student_health_records_school_name_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX student_health_records_school_name_index ON public.student_health_records USING btree (school_name);


--
-- Name: account_requests account_requests_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_requests
    ADD CONSTRAINT account_requests_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: accounts accounts_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: consultations consultations_condition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.consultations
    ADD CONSTRAINT consultations_condition_id_foreign FOREIGN KEY (condition_id) REFERENCES public.conditions(id) ON DELETE SET NULL;


--
-- Name: consultations consultations_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.consultations
    ADD CONSTRAINT consultations_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: deworming_requests deworming_requests_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deworming_requests
    ADD CONSTRAINT deworming_requests_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: feeding_attendances feeding_attendances_student_health_record_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.feeding_attendances
    ADD CONSTRAINT feeding_attendances_student_health_record_id_foreign FOREIGN KEY (student_health_record_id) REFERENCES public.student_health_records(id) ON DELETE CASCADE;


--
-- Name: health_assessments health_assessments_student_health_record_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.health_assessments
    ADD CONSTRAINT health_assessments_student_health_record_id_foreign FOREIGN KEY (student_health_record_id) REFERENCES public.student_health_records(id) ON DELETE CASCADE;


--
-- Name: medical_certificates medical_certificates_student_health_condition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medical_certificates
    ADD CONSTRAINT medical_certificates_student_health_condition_id_foreign FOREIGN KEY (student_health_condition_id) REFERENCES public.student_health_conditions(id) ON DELETE CASCADE;


--
-- Name: medicines medicines_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.medicines
    ADD CONSTRAINT medicines_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: parental_consent_forms parental_consent_forms_student_health_record_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.parental_consent_forms
    ADD CONSTRAINT parental_consent_forms_student_health_record_id_foreign FOREIGN KEY (student_health_record_id) REFERENCES public.student_health_records(id) ON DELETE CASCADE;


--
-- Name: student_health_records student_health_records_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_health_records
    ADD CONSTRAINT student_health_records_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: users users_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict 1lK0IMEgYF1YeB0ovzrCCdVkQKaxz1e1VSboTYmSKypu4nOCN4du9a8NGn7JWm7

