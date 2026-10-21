# Sequence Diagrams: Digital Mental Health Support Platform

This document outlines the detailed sequence diagrams mapping the critical logic flows of the **Digital Mental Health Support Platform**. These diagrams illustrate the interaction between the Client (Browser/JS), backend PHP REST APIs, and the relational MySQL database.

---

## 1. Combined Self-Assessment Submission & Analysis

This flow illustrates how a student takes the 37-question combined self-assessment (covering GAD-7, PHQ-9, MDQ, PC-PTSD-5, PSS-4, and Rosenberg Self-Esteem Scale), and how the backend parses the answers per-disorder to calculate scores, severity risk categories, and clinical recommendations.

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant UI as Browser UI
    participant JS as js/app.js
    participant API as assessment/submit_assessment.php
    participant DB as MySQL Database

    Student->>UI: Selects "Start Combined Assessment"
    UI->>JS: Triggers multi-scale assessment wizard
    JS->>UI: Displays questions sequentially with disorder section badges
    Student->>UI: Answers all 37 questions and clicks "Submit"
    UI->>JS: Collates answers (array of values 0-3)
    JS->>API: POST /assessment/submit_assessment.php<br/>{assessment_type: 'combined', answers: [...]}
    
    activate API
    API->>API: Validate session & role (Student)
    API->>API: Validate input length (37 answers) & boundaries (values 0-3)
    API->>API: Slice answers array into per-disorder sub-arrays
    API->>API: Compute scores, risk categories & recommendations for all 6 scales
    
    API->>DB: Check & align schema (ADD COLUMN if not exists)
    DB-->>API: Schema ready
    
    API->>DB: INSERT INTO self_assessments<br/>(student_id, assessment_type, answers, score, category, details)
    activate DB
    DB-->>API: Insert Success (lastInsertId)
    deactivate DB
    
    API-->>JS: JSON Response {success: true, data: {score: X, details: {...}}}
    deactivate API
    
    JS->>UI: Renders clinical feedback grid & per-disorder breakdown cards
    UI-->>Student: Displays results dashboard & recommendations
```

- **Related Files:**
  - Client wizard & rendering: [js/app.js](file:///C:/wamp64/www/mental_support/js/app.js)
  - Processing & analysis: [assessment/submit_assessment.php](file:///C:/wamp64/www/mental_support/assessment/submit_assessment.php)
  - History querying: [student/get_assessments.php](file:///C:/wamp64/www/mental_support/student/get_assessments.php)

---

## 2. Student Appointment Booking & Overlap Validation

This diagram details the booking validation flow, including the 1-hour session duration logic, workday bounds, daily counselor booking capacity (max 9 slots), automatic recommended counselor fallback, and group discussion eligibility check.

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant UI as Browser UI
    participant JS as js/app.js
    participant API as student/book_appointment.php
    participant DB as MySQL Database

    Student->>UI: Fills booking form (counselor, date, time slot, session type)
    UI->>JS: Handles submit event
    JS->>API: POST /student/book_appointment.php<br/>{counselor_id, date, time, session_type}
    
    activate API
    API->>API: Verify Student session
    API->>API: Check working hours bounds (08:30 - 16:30 start time)
    API->>API: Verify requested time is in the future
    
    API->>DB: Check if Counselor is active
    DB-->>API: Counselor status active
    
    API->>DB: Check Daily Count: Count existing bookings for counselor on date
    DB-->>API: Returns count (e.g. 9)
    
    alt Daily Count >= 9 (Fully Booked)
        API->>DB: Query for alternative counselor with < 9 bookings on date
        DB-->>API: Returns next available counselor (e.g., Counselor Y)
        API-->>JS: HTTP 400 {success: false, counselor_fully_booked: true, recommended_counselor_id, recommended_counselor_name}
        JS->>UI: Prompts: "Counselor X is fully booked. book with Counselor Y instead?"
        Student->>UI: Accepts recommendation
        UI->>JS: Submits updated booking for Counselor Y
        JS->>API: POST /student/book_appointment.php (updated parameters)
    end

    API->>DB: Check Overlap: Count bookings where start < time+1hr AND time < start+1hr
    DB-->>API: Returns overlap count (e.g. 0)
    
    alt Overlap Count > 0
        API-->>JS: HTTP 400 {success: false, message: 'Counselor already booked'}
        JS->>UI: Displays "counselor booked at this time" alert
    else Overlap Count == 0
        API->>DB: Begin DB Transaction
        
        alt session_type == 'group'
            API->>DB: Check if student has group_recommended = 1 in profile
            DB-->>API: Confirms recommendation status
            API->>DB: Reset group_recommended = 0 & set discussion_choice = 'chat'
        end
        
        API->>DB: INSERT INTO appointments<br/>(student_id, counselor_id, date, time, status, meeting_link, session_type)
        API->>DB: INSERT INTO notifications for Counselor
        API->>DB: Commit DB Transaction
        
        DB-->>API: Success
        API-->>JS: HTTP 200 {success: true, message: 'Appointment booked successfully'}
        deactivate API
        JS->>UI: Shows confirmation message and refreshes appointments tab
    end
```

- **Related Files:**
  - Booking UI controls: [dashboard_student.html](file:///C:/wamp64/www/mental_support/dashboard_student.html)
  - Validation & reservation: [student/book_appointment.php](file:///C:/wamp64/www/mental_support/student/book_appointment.php)

---

## 3. Appointment Auto-Lifecycle Synchronization

The platform performs automatic, real-time lifecycle updates on *every database connection* (triggered by any web load or API call) to enforce constraints on missed and completed sessions.

```mermaid
sequenceDiagram
    autonumber
    actor User as Any User / Request
    participant App as Application Core
    participant DBConn as config/db.php<br/>(get_db_connection)
    participant Sync as check_and_update_appointment_statuses
    participant DB as MySQL Database

    User->>App: Invokes any page or endpoint (e.g., dashboard, assessment, chat)
    App->>DBConn: Calls get_db_connection()
    activate DBConn
    
    DBConn->>DBConn: Initializes PDO Connection
    DBConn->>Sync: Executes status synchronization hook
    activate Sync
    
    Note over Sync, DB: Phase 1: Auto-Complete completed sessions
    Sync->>DB: SELECT student_ids WHERE status='accepted' AND session_started=1<br/>AND scheduled time + 1 hour <= NOW()
    DB-->>Sync: Returns list of student IDs
    
    Sync->>DB: UPDATE appointments SET status='completed' WHERE...
    Sync->>DB: INSERT INTO notifications for each student
    
    Note over Sync, DB: Phase 2: Close unstarted/missed sessions
    Sync->>DB: SELECT ids, student_id, counselor_id WHERE status IN ('pending', 'accepted')<br/>AND session_started=0 AND scheduled time + 15 minutes <= NOW()
    DB-->>Sync: Returns list of missed appointments
    
    Sync->>DB: UPDATE appointments SET status='missing' WHERE...
    Sync->>DB: INSERT INTO notifications for students and counselors
    
    deactivate Sync
    DBConn-->>App: Returns active PDO handler
    deactivate DBConn
    App-->>User: Renders UI page or returns API JSON response
```

- **Related Files:**
  - Database & hook: [config/db.php](file:///C:/wamp64/www/mental_support/config/db.php)

---

## 4. Counselor Session Completion & Manual Group Recommendation

This diagram maps the counselor manually completing a session, prompting them to recommend group discussion support, and the subsequent student booking workflow.

```mermaid
sequenceDiagram
    autonumber
    actor Counselor
    participant CUI as Counselor UI
    participant JS as js/app.js
    participant API as counselor/update_appointment.php
    participant DB as MySQL Database
    actor Student
    participant SUI as Student UI

    Counselor->>CUI: Clicks "Mark Completed" on scheduled appointment
    CUI->>JS: Triggers event handler
    JS->>CUI: Launches completeSessionModal modal
    Counselor->>CUI: Chooses "Yes, Recommend & Complete" (recommend_group: true)
    CUI->>JS: Captures choice
    JS->>API: POST /counselor/update_appointment.php<br/>{appointment_id, status: 'completed', recommend_group: true}
    
    activate API
    API->>API: Authenticate Counselor & verify appointment ownership
    API->>DB: Begin DB Transaction
    API->>DB: UPDATE appointments SET status = 'completed' WHERE id = X
    
    alt recommend_group == true
        API->>DB: UPDATE students_profile SET group_recommended = 1, discussion_choice = 'none'
        API->>DB: INSERT recommendation notification for Student
    end
    
    API->>DB: INSERT completion notification for Student
    API->>DB: Commit Transaction
    DB-->>API: Success
    API-->>JS: JSON Response {success: true}
    deactivate API
    JS->>CUI: Updates list view & shows completion alert

    Note over Student, SUI: Next Student Login / Refresh
    Student->>SUI: Student logs in / opens Student Dashboard
    SUI->>JS: Calls loadStudentDashboard()
    JS->>DB: Fetch user profile (group_recommended state)
    DB-->>JS: Returns group_recommended = 1
    JS->>SUI: Displays discussionChoiceModal modal ("Book Group Session")
    
    Student->>SUI: Clicks "Book Group Session"
    SUI->>JS: Redirects to booking tab & selects "group" session type
```

- **Related Files:**
  - Counselor dashboard: [dashboard_counselor.html](file:///C:/wamp64/www/mental_support/dashboard_counselor.html)
  - Completion logic: [counselor/update_appointment.php](file:///C:/wamp64/www/mental_support/counselor/update_appointment.php)
  - Student dashboard: [dashboard_student.html](file:///C:/wamp64/www/mental_support/dashboard_student.html)

---

## 5. Community Group Discussion Chat & Polling Loop

This diagram outlines how community group messages are sent and retrieved in real-time by participants (students, counselors, or administrators) via continuous polling.

```mermaid
sequenceDiagram
    autonumber
    actor Participant as Student, Counselor, or Admin
    participant UI as Dashboard UI
    participant JS as js/app.js
    participant SendAPI as chat/send_message.php
    participant RecvAPI as chat/get_messages.php
    participant DB as MySQL Database

    Note over Participant, JS: Message Send Sequence
    Participant->>UI: Types message in "Community Discussions" tab & clicks Send
    UI->>JS: Invokes sendGroupMessage()
    JS->>SendAPI: POST /chat/send_message.php<br/>{chat_type: 'group', message: 'Hello community'}
    
    activate SendAPI
    SendAPI->>SendAPI: Validate session & retrieve sender ID/role
    SendAPI->>DB: INSERT INTO group_discussions (sender_id, content)
    DB-->>SendAPI: Success
    SendAPI-->>JS: JSON Response {success: true}
    deactivate SendAPI
    JS->>UI: Clears input box & immediately calls loadGroupMessages()

    Note over JS, RecvAPI: Message Retrieval / Polling Loop (Every 4s)
    loop Every 4 seconds
        JS->>RecvAPI: GET /chat/get_messages.php?action=group
        activate RecvAPI
        RecvAPI->>DB: SELECT messages JOIN users, students_profile, and counselors<br/>(COALESCE sender names)
        DB-->>RecvAPI: Returns array of group messages
        RecvAPI-->>JS: JSON Response [{id, sender_name, sender_role, content, timestamp}]
        deactivate RecvAPI
        JS->>UI: Formats and inserts message bubbles into #discussionMessagesBox
        UI->>UI: Auto-scrolls chat window to the bottom
    end
```

- Related Files:
  - Messaging interface: [dashboard_student.html](file:///C:/wamp64/www/mental_support/dashboard_student.html) & [dashboard_counselor.html](file:///C:/wamp64/www/mental_support/dashboard_counselor.html)
  - Direct message endpoints: [chat/send_message.php](file:///C:/wamp64/www/mental_support/chat/send_message.php) & [chat/get_messages.php](file:///C:/wamp64/www/mental_support/chat/get_messages.php)
  - Messaging loops: [js/app.js](file:///C:/wamp64/www/mental_support/js/app.js)

---

## 6. Student Account Self-Registration

Only students are allowed to self-register via the landing page registration form.

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant UI as Browser UI
    participant JS as js/app.js
    participant API as auth/register.php
    participant DB as MySQL Database
    participant SMTP as config/mail.php

    Student->>UI: Fills student registration form (name, email, password, university, student_id, phone, department, bio)
    UI->>JS: Handles submit event
    JS->>API: POST /auth/register.php<br/>{role: 'student', name, email, password, university, student_id, phone, department, bio}
    
    activate API
    API->>API: Validate role is 'student' (reject other roles with HTTP 400)
    API->>API: Validate email and password rules
    API->>DB: Check if email is already registered
    DB-->>API: Email status (free or registered)
    
    API->>DB: Check if Student ID is registered under any tertiary
    DB-->>API: Returns registration status
    
    API->>DB: Begin DB Transaction
    API->>DB: INSERT INTO users (email, password_hash, role='student')
    API->>DB: INSERT INTO students_profile (user_id, name, university, phone, student_id, department, bio)
    API->>DB: Commit DB Transaction
    DB-->>API: Success
    
    API->>SMTP: send_email() to Student
    SMTP-->>API: Email dispatched
    
    API-->>JS: JSON Response {success: true, message: 'Registration successful!'}
    deactivate API
    JS->>UI: Closes modal and redirects to Login modal
```

- **Related Files:**
  - Registration UI form: [index.html](file:///C:/wamp64/www/mental_support/index.html)
  - Processing & registration: [auth/register.php](file:///C:/wamp64/www/mental_support/auth/register.php)

---

## 7. Admin Counselor Creation & Welcome Credentials Email

Administrators create counselors directly in the admin dashboard, immediately activating them and sending their login credentials.

```mermaid
sequenceDiagram
    autonumber
    actor Admin
    participant UI as Admin Dashboard UI
    participant JS as js/app.js
    participant API as admin/manage_users.php
    participant DB as MySQL Database
    participant SMTP as config/mail.php

    Admin->>UI: Opens '+ Add Counselor' form (name, email, password, specialty, bio)
    UI->>JS: Handles submit event
    JS->>API: POST /admin/manage_users.php<br/>{action: 'add_counselor', name, email, password, specialty, bio}
    
    activate API
    API->>API: Verify Admin session
    API->>API: Validate required fields and formats
    API->>DB: Check if email is already registered
    DB-->>API: Email status
    
    API->>DB: Begin DB Transaction
    API->>DB: INSERT INTO users (email, password_hash, role='counselor')
    API->>DB: INSERT INTO counselors (user_id, name, specialty, bio, status='active')
    API->>DB: Commit DB Transaction
    DB-->>API: Success
    
    API->>SMTP: send_email() to Counselor with login credentials
    SMTP-->>API: Email dispatched
    
    API-->>JS: JSON Response {success: true, message: 'Counselor added successfully!'}
    deactivate API
    JS->>UI: Closes modal, reloads active counselor list and statistics
```

- **Related Files:**
  - Admin Dashboard UI: [dashboard_admin.html](file:///C:/wamp64/www/mental_support/dashboard_admin.html)
  - Admin User API: [admin/manage_users.php](file:///C:/wamp64/www/mental_support/admin/manage_users.php)
  - Core event handling: [js/app.js](file:///C:/wamp64/www/mental_support/js/app.js)
