
/* ==========================================================================
   AI CHATBOT SUMMARY & PROGRESS REPORT — COUNSELOR FLOW
   ========================================================================== */

let _aiSummaryCurrentAppointmentId = null;

/**
 * Open the Session Progress Report modal for a completed appointment.
 */
async function openAiSummaryModal(appointmentId) {
    _aiSummaryCurrentAppointmentId = appointmentId;

    const metaEl    = document.getElementById('aiSummaryMeta');
    const noteEl    = document.getElementById('aiSummaryNoteTextarea');
    const successEl = document.getElementById('aiSummarySuccess');
    const submitBtn = document.getElementById('aiSummarySubmitBtn');

    if (metaEl)    metaEl.innerHTML    = '<span style="color:var(--text-muted)">Loading session data...</span>';
    if (noteEl)    noteEl.value        = '';
    if (successEl) successEl.style.display = 'none';
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Progress Report'; }

    openModal('aiSummaryModal');

    const result = await apiRequest('counselor/progress_report.php?appointment_id=' + appointmentId);
    if (!result.success) {
        if (metaEl) metaEl.innerHTML = '<span style="color:var(--danger)">' + (result.message || 'Error loading report data') + '</span>';
        return;
    }

    const d = result.data;

    if (metaEl) {
        metaEl.innerHTML =
            '<div style="display:flex; gap:16px; flex-wrap:wrap; background:rgba(255,255,255,0.03); border-radius:8px; padding:10px 14px;">' +
            '<span><strong style="color:var(--text-primary)">Student:</strong> ' + _escHtml(d.student_name) + '</span>' +
            '<span><strong style="color:var(--text-primary)">Dept:</strong> ' + _escHtml(d.department || 'N/A') + '</span>' +
            '<span><strong style="color:var(--text-primary)">Session:</strong> ' + _escHtml(d.session_type || 'N/A') + '</span>' +
            '<span><strong style="color:var(--text-primary)">Date:</strong> ' + _escHtml(d.date) + ' at ' + _escHtml(d.time) + '</span>' +
            '</div>';
    }

    if (noteEl && d.counselor_progress_note) {
        noteEl.value = d.counselor_progress_note;
    }
    if (successEl && d.progress_note_submitted_at) {
        successEl.style.display = 'block';
        successEl.innerHTML = 'Progress report last saved on ' + new Date(d.progress_note_submitted_at).toLocaleDateString() + '. You can update it below.';
    }
}


/**
  * Submit the counselor progress report to the server.
 */
async function submitProgressNote() {
    if (!_aiSummaryCurrentAppointmentId) return;
    const noteEl    = document.getElementById('aiSummaryNoteTextarea');
    const submitBtn = document.getElementById('aiSummarySubmitBtn');
    const successEl = document.getElementById('aiSummarySuccess');
    const note      = noteEl ? noteEl.value.trim() : '';

    if (!note) {
        alert('Please write a progress report before submitting.');
        return;
    }

    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving...'; }

    const result = await apiRequest('counselor/progress_report.php', 'POST', {
        appointment_id: _aiSummaryCurrentAppointmentId,
        note: note
    });

    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Progress Report'; }

    if (result.success) {
        if (successEl) {
            successEl.style.display = 'block';
            successEl.innerHTML = 'Progress report submitted successfully. The lecturer can now view this report.';
        }
    } else {
        alert('Failed to save progress report: ' + result.message);
    }
}

/** HTML entity escape helper */
function _escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}


/* ==========================================================================
   LECTURER PORTAL — PROGRESS NOTES INTEGRATION
   ========================================================================== */

/**
 * Wraps the existing openLecturerStudentDetailsModal to also load
 * counselor-submitted progress notes after the health record loads.
 */
(function() {
    const _origDetailsModal = window.openLecturerStudentDetailsModal;
    window.openLecturerStudentDetailsModal = async function(studentId) {
        if (typeof _origDetailsModal === 'function') {
            await _origDetailsModal(studentId);
        }
        await _loadProgressNotesForLecturer(studentId);
    };
})();

async function _loadProgressNotesForLecturer(studentId) {
    const container = document.getElementById('studentAuditDetails');
    if (!container) return;

    // Remove stale section
    const existingSection = document.getElementById('progressNotesSectionLecturer');
    if (existingSection) existingSection.remove();

    // Create placeholder
    const section = document.createElement('div');
    section.id = 'progressNotesSectionLecturer';
    section.style.marginTop = '28px';
    section.innerHTML =
        '<h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 12px; font-weight: 600;">' +
        'Counselor Progress Reports</h4>' +
        '<p style="color: var(--text-muted); font-size: 13px;">Loading progress notes...</p>';
    container.appendChild(section);

    const result = await apiRequest('lecturer/progress_reports.php?student_id=' + studentId);

    if (!result.success) {
        section.innerHTML =
            '<h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 12px; font-weight: 600;">Counselor Progress Reports</h4>' +
            '<p style="color:var(--danger); font-size:13px;">' + (result.message || 'Failed to load') + '</p>';
        return;
    }

    const reports = result.data || [];

    if (reports.length === 0) {
        section.innerHTML =
            '<h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 12px; font-weight: 600;">Counselor Progress Reports</h4>' +
            '<div style="background: rgba(255,255,255,0.03); border-radius: 10px; padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px;">' +
            'No counselor progress reports have been submitted for this student yet.' +
            '</div>';
        return;
    }

    let html =
        '<h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">' +
        'Counselor Progress Reports ' +
        '<span style="background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 4px;">' + reports.length + '</span>' +
        '</h4>' +
        '<div style="display: flex; flex-direction: column; gap: 16px; max-height: 400px; overflow-y: auto;">';

    reports.forEach(function(r, idx) {
        const submittedDate = r.progress_note_submitted_at
            ? new Date(r.progress_note_submitted_at).toLocaleString()
            : 'Unknown date';
        const sessionLabel = r.session_type
            ? r.session_type.charAt(0).toUpperCase() + r.session_type.slice(1)
            : 'Session';

        html +=
            '<div style="background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 12px; overflow: hidden;">' +
            // Header
            '<div style="background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.08)); padding: 12px 16px; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">' +
            '<div>' +
            '<div style="font-weight: 700; color: var(--text-primary); font-size: 14px;">Report #' + (idx + 1) + ' &mdash; ' + sessionLabel + ' Session</div>' +
            '<div style="font-size: 12px; color: var(--text-secondary); margin-top: 3px;">' + _escHtml(r.date) + ' at ' + _escHtml(r.time) + '</div>' +
            '</div>' +
            '<div style="text-align: right; flex-shrink: 0;">' +
            '<div style="font-size: 11px; color: var(--text-muted);">Submitted by</div>' +
            '<div style="font-size: 13px; font-weight: 600; color: var(--primary);">' + _escHtml(r.counselor_name) + '</div>' +
            '<div style="font-size: 11px; color: var(--text-muted);">' + _escHtml(r.counselor_specialty || '') + '</div>' +
            '</div>' +
            '</div>' +
            // Body
            '<div style="padding: 16px; font-size: 13px; color: var(--text-primary); line-height: 1.75; white-space: pre-wrap; max-height: 180px; overflow-y: auto;">' +
            _escHtml(r.counselor_progress_note) +
            '</div>' +
            // Footer
            '<div style="padding: 8px 16px; background: rgba(0,0,0,0.15); font-size: 11px; color: var(--text-muted); text-align: right;">' +
            'Filed on ' + submittedDate +
            '</div>' +
            '</div>';
    });

    html += '</div>';
    section.innerHTML = html;
}
