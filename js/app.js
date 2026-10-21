/**
 * Master Frontend Controller - Digital Mental Health Platform
 * Manages AJAX API interactions, routing tabs, live chat polling, and questionnaire logic.
 */

// Application State
const App = {
    user: null,
    chatPartnerId: null,
    chatPartnerName: '',
    chatPollInterval: null,
    groupPollInterval: null,
    lastChatTimestamp: null,
    lastGroupTimestamp: null,
    assessmentQuestions: [],
    assessmentAnswers: {},
    currentQuestionIndex: 0,
    notificationInterval: null,
    urgentBooking: null,  // { isUrgent: true, disorder: 'Anxiety' } when critical assessment redirect
    tabHistory: []
};

/**
 * Reusable Fetch wrapper for PHP APIs
 */
async function apiRequest(url, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'ngrok-skip-browser-warning': 'true'
        }
    };

    if (data) {
        if (data instanceof FormData) {
            options.body = data;
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }
    }

    try {
        const response = await fetch(url, options);
        
        if (response.status === 401 && !url.includes('auth/login.php') && !url.includes('auth/logout.php')) {
            // Unauthorized / Session Expired
            localStorage.removeItem('mhp_user');
            sessionStorage.clear();
            alert('Your session has expired. Redirecting to login.');
            window.location.replace('index.html?error=session_expired');
            return { success: false, message: 'Session expired' };
        }
        
        const rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error('Invalid JSON response from', url, ':', rawText);
            return { success: false, message: 'Server returned HTML instead of JSON. Please refresh the page.' };
        }

        return result;
    } catch (error) {
        console.error('API Error:', error);
        return { success: false, message: 'Network error or invalid server response.' };
    }
}

/**
 * Handle user registration form submit
 */
async function handleRegister(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Creating account...';
    submitBtn.disabled = true;

    const result = await apiRequest('auth/register.php', 'POST', formData);
    
    submitBtn.innerText = originalText;
    submitBtn.disabled = false;

    if (result.success) {
        alert(result.message);
        form.reset();
        closeModal('registerModal');
        openModal('loginModal');
    } else {
        alert('Registration Error: ' + result.message);
    }
}

/**
 * Handle user login form submit
 */
async function handleLogin(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.innerText = 'Logging in...';
    submitBtn.disabled = true;

    const result = await apiRequest('auth/login.php', 'POST', formData);

    if (result.success) {
        // Save user state
        App.user = result.data.user;
        localStorage.setItem('mhp_user', JSON.stringify(result.data.user));
        
        // Redirect to appropriate dashboard
        window.location.href = result.data.redirect;
    } else {
        submitBtn.innerText = 'Log In';
        submitBtn.disabled = false;
        alert('Login Failed: ' + result.message);
    }
}

/**
 * Handle user logout
 */
async function handleLogout() {
    if (confirm('Are you sure you want to log out?')) {
        localStorage.removeItem('mhp_user');
        sessionStorage.clear();
        try {
            await fetch('auth/logout.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } catch (e) {}
        window.location.replace('index.html');
    }
}

/**
 * General Dashboard Setup
 */
function initDashboard(role) {
    // Read user from localStorage
    const savedUser = localStorage.getItem('mhp_user');
    if (!savedUser) {
        window.location.href = 'index.html?error=unauthorized';
        return;
    }
    App.user = JSON.parse(savedUser);
    
    if (App.user.role !== role) {
        window.location.href = 'index.html?error=unauthorized';
        return;
    }

    // Set greeting name and avatar
    const greetingEl = document.getElementById('userGreeting');
    if (greetingEl) greetingEl.innerText = App.user.name;

    const universityEl = document.getElementById('userUniversity');
    if (universityEl && App.user.university) {
        universityEl.innerText = App.user.university;
    }

    const avatarPlaceholder = document.getElementById('avatarLetter');
    if (avatarPlaceholder) {
        if (App.user.profile_picture) {
            avatarPlaceholder.innerHTML = `<img src="${App.user.profile_picture}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
        } else {
            avatarPlaceholder.innerText = App.user.name.charAt(0).toUpperCase();
        }
    }

    // Sidebar tab routing setup
    const sidebarLinks = document.querySelectorAll('.sidebar-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', () => {
            const targetTab = link.getAttribute('data-tab');
            switchTab(targetTab);
        });
    });

    // Start checking notifications & incoming group video call invites
    loadNotifications();
    App.notificationInterval = setInterval(loadNotifications, 8000);

    if (role === 'student') {
        checkIncomingGroupCall();
        setInterval(checkIncomingGroupCall, 4000);
    }

    // Trigger initial active tab switch on page load
    const activeLink = document.querySelector('.sidebar-link.active');
    if (activeLink) {
        const defaultTab = activeLink.getAttribute('data-tab');
        switchTab(defaultTab, true);
    }
}

/**
 * Go back to previously visited step or tab in dashboard
 */
function goBackStep() {
    // 1. Check if an active modal overlay is open
    const openModals = document.querySelectorAll('.modal-overlay.active, .modal-overlay[style*="display: flex"], .modal-overlay[style*="display:flex"]');
    for (let modal of openModals) {
        if (modal.id !== 'videoConsultationModal' && window.getComputedStyle(modal).display !== 'none') {
            closeModal(modal.id);
            modal.style.display = 'none';
            modal.classList.remove('active');
            return;
        }
    }

    // 2. Check if currently inside Assessment Wizard
    const wizardContainer = document.getElementById('assessmentWizardContainer');
    if (wizardContainer && window.getComputedStyle(wizardContainer).display !== 'none' && wizardContainer.innerHTML.trim() !== '') {
        if (App.currentQuestionIndex > 0) {
            prevAssessmentQuestion();
            return;
        } else {
            resetToAssessmentSelection();
            return;
        }
    }

    // 3. Check if currently inside active 1-on-1 Chat Conversation
    const chatMessagesBox = document.getElementById('chatMessagesBox');
    if (App.chatPartnerId) {
        App.chatPartnerId = null;
        App.chatPartnerName = '';
        if (typeof stopChatPolling === 'function') stopChatPolling();
        const partnerNameEl = document.getElementById('chatPartnerName');
        if (partnerNameEl) partnerNameEl.textContent = 'Select a conversation';
        if (chatMessagesBox) {
            chatMessagesBox.innerHTML = '<div style="display: flex; height: 100%; align-items: center; justify-content: center; color: var(--text-muted);"><p>Select a contact to view messages.</p></div>';
        }
        if (typeof loadChatConversationsList === 'function') loadChatConversationsList();
        return;
    }

    // 4. Check tab history stack
    if (App.tabHistory && App.tabHistory.length > 0) {
        const prevTab = App.tabHistory.pop();
        switchTab(prevTab, true);
        return;
    }

    // 5. Fallback to role-specific default home tab
    const defaultTabs = {
        student: 'profileTab',
        counselor: 'appointmentsTab',
        lecturer: 'studentsProgressTab',
        admin: 'adminOverviewTab'
    };

    const userRole = (App.user && App.user.role) ? App.user.role : 'student';
    const defaultTab = defaultTabs[userRole] || 'profileTab';
    const currentActive = document.querySelector('.tab-content.active');

    if (currentActive && currentActive.id !== defaultTab) {
        switchTab(defaultTab, true);
        return;
    }

    // 6. Referrer fallback if user arrived from another manual or doc page
    if (document.referrer && document.referrer.includes('user_manual.html')) {
        window.location.href = document.referrer;
    }
}

/**
 * Switch Active Tab in Dashboard
 */
function switchTab(tabId, isBack = false) {
    if (!App.tabHistory) App.tabHistory = [];
    
    // Find currently active tab before switching
    const currentActive = document.querySelector('.tab-content.active');
    const currentTabId = currentActive ? currentActive.id : null;

    if (!isBack && currentTabId && currentTabId !== tabId) {
        if (App.tabHistory.length === 0 || App.tabHistory[App.tabHistory.length - 1] !== currentTabId) {
            App.tabHistory.push(currentTabId);
        }
    }

    // Push browser history state so browser Back button also works
    if (!isBack && history.pushState && location.hash !== '#' + tabId) {
        try {
            history.pushState({ tab: tabId }, '', '#' + tabId);
        } catch (e) {}
    }

    // Deactivate current tabs
    document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    // Stop live chat poll if moving away from chat tab
    if (tabId !== 'chatTab') {
        stopChatPolling();
    }
    if (tabId !== 'discussionTab') {
        stopGroupPolling();
    }

    // Activate selected tab
    const link = document.querySelector(`.sidebar-link[data-tab="${tabId}"]`);
    const content = document.getElementById(tabId);
    
    if (link) link.classList.add('active');
    if (content) content.classList.add('active');

    // Tab specific load actions
    if (tabId === 'profileTab' && App.user.role === 'student') {
        App.urgentBooking = null; // Clear urgent state if going back to overview
        loadStudentProfileDetails();
    } else if (tabId === 'appointmentsTab' && App.user.role === 'student') {
        loadStudentAppointments();
        const specialty = (App.urgentBooking && App.urgentBooking.isUrgent) ? App.urgentBooking.disorder : null;
        loadCounselorsListForBooking(specialty);
    } else if (tabId === 'appointmentsTab' && App.user.role === 'counselor') {
        loadCounselorAppointments();
    } else if (tabId === 'studentAssessmentsTab' && App.user.role === 'counselor') {
        loadCounselorStudentAssessments();
    } else if (tabId === 'assessmentTab') {
        loadStudentAssessmentsHistory();
    } else if (tabId === 'storiesTab') {
        loadStoriesFeed();
    } else if (tabId === 'chatbotTab') {
        // AI Chatbot tab selected, nothing special to init
    } else if (tabId === 'adminUsersTab') {
        loadUsersList('student');
    } else if (tabId === 'adminModerateTab') {
        loadStoriesForModeration();
    } else if (tabId === 'adminOverviewTab') {
        loadAdminOverviewStats();
    } else if (tabId === 'adminUniversitiesTab') {
        loadUniversitiesList();
    } else if (tabId === 'chatTab') {
        initChatPortal();
    } else if (tabId === 'discussionTab') {
        initGroupDiscussionPortal();
    }
}

/* ==========================================================================
   STUDENT PORTAL LOGIC
   ========================================================================== */

/**
 * Load student profile fields
 */
async function loadStudentProfileDetails() {
    // Since profile fields are fetched on registration/login or profile update
    // We can pre-fill the name. Let's make a request to check current profile details if needed.
    // For simplicity, we fill from localStorage name, and let user edit.
    const nameInput = document.getElementById('profileName');
    if (nameInput) nameInput.value = App.user.name;
}

/**
 * Submit profile updates
 */
async function handleProfileUpdate(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    const result = await apiRequest('student/update_profile.php', 'POST', formData);
    if (result.success) {
        alert(result.message);
        App.user.name = result.data.name;
        localStorage.setItem('mhp_user', JSON.stringify(App.user));
        document.getElementById('userGreeting').innerText = App.user.name;
    } else {
        alert('Update Failed: ' + result.message);
    }
}

/**
 * Handle Avatar Upload
 */
async function handleAvatarUpload(event) {
    const fileInput = event.target;
    if (!fileInput.files || fileInput.files.length === 0) return;

    const file = fileInput.files[0];
    if (file.size > 2 * 1024 * 1024) {
        alert('File size exceeds 2MB limit. Please choose a smaller image.');
        return;
    }

    const formData = new FormData();
    formData.append('avatar', file);

    const avatarPlaceholder = document.getElementById('avatarLetter');
    if (avatarPlaceholder) avatarPlaceholder.style.opacity = '0.5';

    const result = await apiRequest('auth/upload_avatar.php', 'POST', formData);
    
    if (avatarPlaceholder) avatarPlaceholder.style.opacity = '1';

    if (result.success) {
        App.user.profile_picture = result.data.profile_picture;
        localStorage.setItem('mhp_user', JSON.stringify(App.user));
        
        if (avatarPlaceholder) {
            avatarPlaceholder.innerHTML = `<img src="${App.user.profile_picture}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
        }
        alert('Profile picture uploaded successfully!');
    } else {
        alert('Upload failed: ' + result.message);
    }
}

const APPOINTMENT_HOURLY_SLOTS = [
    { value: '08:00', label: '8:00 AM' },
    { value: '09:00', label: '9:00 AM' },
    { value: '10:00', label: '10:00 AM' },
    { value: '11:00', label: '11:00 AM' },
    { value: '12:00', label: '12:00 PM' },
    { value: '13:00', label: '1:00 PM' },
    { value: '14:00', label: '2:00 PM' },
    { value: '15:00', label: '3:00 PM' },
    { value: '16:00', label: '4:00 PM' }
];

/**
 * Fetch and render time slots for selected counselor & date
 * Booked slots: light red background, non-selectable with warning on click
 * Unselected available slots: white background
 */
async function fetchAndRenderCounselorTimeSlots() {
    const counselorEl = document.getElementById('bookingCounselor');
    const dateEl = document.getElementById('bookingDate');
    const container = document.getElementById('timeSlotsContainer');
    const timeInput = document.getElementById('bookingTimeSlot');
    const warningEl = document.getElementById('timeSlotWarning');

    if (!container) return;

    if (warningEl) {
        warningEl.style.display = 'none';
        warningEl.textContent = '';
    }

    if (timeInput) timeInput.value = '';

    const counselorId = counselorEl ? counselorEl.value : '';
    const dateVal = dateEl ? dateEl.value : '';

    if (!counselorId || !dateVal) {
        container.innerHTML = '<p style="color: var(--text-secondary); font-size: 13px; grid-column: 1/-1;">Select date and counselor above to load time slots.</p>';
        return;
    }

    container.innerHTML = '<p style="color: var(--text-secondary); font-size: 13px; grid-column: 1/-1;">Loading available time slots...</p>';

    try {
        const res = await apiRequest(`student/get_appointments.php?action=get_booked_slots&counselor_id=${counselorId}&date=${encodeURIComponent(dateVal)}`);
        const bookedSlots = (res && res.success && res.booked_slots) ? res.booked_slots : [];

        container.innerHTML = '';

        APPOINTMENT_HOURLY_SLOTS.forEach(slot => {
            const isBooked = bookedSlots.includes(slot.value);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.dataset.slotValue = slot.value;
            btn.dataset.slotLabel = slot.label;
            btn.style.padding = '10px 6px';
            btn.style.borderRadius = '10px';
            btn.style.fontSize = '12.5px';
            btn.style.fontWeight = '700';
            btn.style.textAlign = 'center';
            btn.style.transition = 'all 0.2s ease';
            btn.style.display = 'flex';
            btn.style.flexDirection = 'column';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.style.gap = '2px';

            if (isBooked) {
                // Booked time slots: light red background, NOT deep red
                btn.style.background = '#fee2e2'; // Light red
                btn.style.color = '#991b1b';       // Dark red text
                btn.style.border = '1px solid #fca5a5';
                btn.style.cursor = 'not-allowed';
                btn.style.opacity = '0.9';
                btn.innerHTML = `<span>${slot.label}</span><span style="font-size:10px; font-weight:600; text-transform:uppercase;">Booked</span>`;
                
                btn.onclick = function(e) {
                    e.preventDefault();
                    const warningMsg = `⚠️ Warning: The ${slot.label} time slot is already booked for this counselor on ${dateVal}. Please select an available white time slot.`;
                    if (warningEl) {
                        warningEl.textContent = warningMsg;
                        warningEl.style.display = 'block';
                    }
                    alert(warningMsg);
                };
            } else {
                // Unselected / available slots: MUST remain white background
                btn.style.background = '#ffffff'; // White
                btn.style.color = '#1e293b';       // Dark text
                btn.style.border = '1px solid #cbd5e1';
                btn.style.cursor = 'pointer';
                btn.innerHTML = `<span>${slot.label}</span><span style="font-size:10px; font-weight:600; color:#10b981; text-transform:uppercase;">Available</span>`;

                btn.onclick = function(e) {
                    e.preventDefault();
                    if (warningEl) {
                        warningEl.style.display = 'none';
                        warningEl.textContent = '';
                    }

                    // Reset all unbooked slot buttons back to white background
                    container.querySelectorAll('button').forEach(b => {
                        if (b.dataset.isBooked === 'true') return;
                        b.style.background = '#ffffff';
                        b.style.color = '#1e293b';
                        b.style.border = '1px solid #cbd5e1';
                        b.style.boxShadow = 'none';
                    });

                    // Highlight selected available slot button
                    btn.style.background = '#0d9488'; // Primary Teal
                    btn.style.color = '#ffffff';
                    btn.style.border = '1px solid #0f766e';
                    btn.style.boxShadow = '0 2px 8px rgba(13,148,136,0.3)';

                    if (timeInput) timeInput.value = slot.value;
                };
            }

            btn.dataset.isBooked = isBooked ? 'true' : 'false';
            container.appendChild(btn);
        });

    } catch (err) {
        console.error('Error fetching time slots:', err);
        container.innerHTML = '<p style="color: var(--danger); font-size: 13px; grid-column: 1/-1;">Error loading time slots. Please re-select counselor or date.</p>';
    }
}

/**
 * Load list of counselors for booking dropdown
 */
async function loadCounselorsListForBooking(specialtyFilter = null) {
    const bookingDateInput = document.getElementById('bookingDate');
    if (bookingDateInput) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        bookingDateInput.min = `${yyyy}-${mm}-${dd}`;
        if (!bookingDateInput.value) {
            bookingDateInput.value = `${yyyy}-${mm}-${dd}`;
        }
    }

    const dropdown = document.getElementById('bookingCounselor');
    if (!dropdown) return;

    dropdown.innerHTML = '<option value="">Loading counselors...</option>';
    
    // Banner control
    const banner = document.getElementById('urgentBookingBanner');
    if (specialtyFilter) {
        if (banner) {
            banner.innerHTML = `
                <div class="urgent-banner">
                    <strong>🚨 Immediate Attention Required</strong><br>
                    Your self-assessment indicates a critical level of distress. 
                    The list of counselors below is filtered to show experts specializing in <strong>${specialtyFilter}</strong>.
                </div>
            `;
            banner.style.display = 'block';
        }
    } else {
        if (banner) {
            banner.innerHTML = '';
            banner.style.display = 'none';
        }
    }

    let url = 'student/get_appointments.php?action=list_counselors';
    if (specialtyFilter) {
        url += '&specialty=' + encodeURIComponent(specialtyFilter);
    }
    
    const result = await apiRequest(url);
    
    if (result.success) {
        dropdown.innerHTML = '<option value="">-- Select Counselor --</option>';
        const counselorsList = result.data.counselors || result.data;
        const activeCounselors = counselorsList.filter(c => c.status === 'active');
        
        if (activeCounselors.length === 0) {
            if (specialtyFilter) {
                dropdown.innerHTML = '<option value="">No specialists found; please choose another counselor</option>';
                // If no specialists found, load all as fallback
                setTimeout(() => loadCounselorsListForBooking(null), 1500);
            } else {
                dropdown.innerHTML = '<option value="">No active counselors available</option>';
            }
            return;
        }

        activeCounselors.forEach((c, idx) => {
            const selected = (specialtyFilter && idx === 0) ? 'selected' : '';
            dropdown.innerHTML += `<option value="${c.user_id}" ${selected}>${c.name} (${c.specialty})</option>`;
        });

        // Handle group discussions session type availability
        const groupRecommended = result.data.group_recommended || 0;
        const discussionChoice = result.data.discussion_choice || 'none';
        
        // Show support group modal if recommended and choice is pending
        if (groupRecommended && discussionChoice === 'none') {
            openModal('discussionChoiceModal');
        }

        const sessionTypeDropdown = document.getElementById('bookingSessionType');
        if (sessionTypeDropdown) {
            const groupOption = sessionTypeDropdown.querySelector('option[value="group"]');
            if (groupOption) {
                if (groupRecommended) {
                    groupOption.disabled = false;
                    groupOption.innerText = "Group Discussions (Recommended by Counselor)";
                } else {
                    groupOption.disabled = true;
                    groupOption.innerText = "Group Discussions (Requires Counselor Recommendation)";
                }
            }
        }

        // Check compulsory assessment status
        const hasAssessment = result.data.has_assessment !== false;
        App.hasCompletedAssessment = hasAssessment;

        if (!hasAssessment) {
            if (banner) {
                banner.innerHTML = `
                    <div style="background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; border-radius: 12px; padding: 16px; margin-bottom: 20px; color: #fff;">
                        <h4 style="color: #ef4444; margin: 0 0 6px; font-weight: 700;">⚠️ Assessment Required Before Booking</h4>
                        <p style="font-size: 13.5px; color: var(--text-secondary); margin: 0 0 12px;">Taking a self-assessment is compulsory to determine your mental health risk level before requesting a consultation session.</p>
                        <button class="btn-primary" onclick="switchTab('assessmentTab'); startSelfAssessment('combined');" style="background: #ef4444; border-color: #ef4444; cursor: pointer;">
                            📋 Take Self-Assessment Now
                        </button>
                    </div>
                `;
                banner.style.display = 'block';
            }
        }

        // Fetch & render time slots for selected counselor and date
        fetchAndRenderCounselorTimeSlots();

    } else {
        dropdown.innerHTML = '<option value="">Failed to load counselors</option>';
    }
}

/**
 * Submit booking details
 */
async function handleBookAppointment(event) {
    event.preventDefault();
    const form = event.target;
    
    // Compulsory assessment check
    if (App.hasCompletedAssessment === false) {
        alert("⚠️ Compulsory Assessment Required\n\nYou must complete a self-assessment first to determine your risk level before booking a therapy consultation.\n\nRedirecting you to complete your assessment now...");
        switchTab('assessmentTab');
        startSelfAssessment('combined');
        return;
    }

    const counselorId = document.getElementById('bookingCounselor').value;
    const sessionType = document.getElementById('bookingSessionType').value;
    const bookingDate = document.getElementById('bookingDate').value;
    const bookingTime = document.getElementById('bookingTimeSlot') ? document.getElementById('bookingTimeSlot').value : '';
    const warningEl = document.getElementById('timeSlotWarning');

    if (!counselorId) {
        alert('Please choose a counselor.');
        return;
    }
    if (!bookingDate) {
        alert('Please select a date.');
        return;
    }
    if (!bookingTime) {
        const warningMsg = '⚠️ Warning: Please click to select an available (white) time slot before submitting your booking request.';
        if (warningEl) {
            warningEl.textContent = warningMsg;
            warningEl.style.display = 'block';
        }
        alert(warningMsg);
        return;
    }

    const data = {
        counselor_id: parseInt(counselorId),
        session_type: sessionType,
        date: bookingDate,
        time: bookingTime
    };

    if (App.urgentBooking && App.urgentBooking.isUrgent) {
        data.is_urgent = 1;
        data.urgency_disorder = App.urgentBooking.disorder;
    }

    const result = await apiRequest('student/book_appointment.php', 'POST', data);
    if (result.success) {
        alert(result.message);
        // Clear urgent state after successful booking
        App.urgentBooking = null;
        const banner = document.getElementById('urgentBookingBanner');
        if (banner) {
            banner.innerHTML = '';
            banner.style.display = 'none';
        }
        form.reset();
        fetchAndRenderCounselorTimeSlots();
        loadStudentAppointments();
    } else {
        if (result.has_no_assessment) {
            alert(`⚠️ Compulsory Requirement:\n\n${result.message}\n\nRedirecting you to complete your assessment now...`);
            switchTab('assessmentTab');
            startSelfAssessment('combined');
            return;
        }
        if (result.double_booked) {
            const warningMsg = `⚠️ Warning: ${result.message}`;
            if (warningEl) {
                warningEl.textContent = warningMsg;
                warningEl.style.display = 'block';
            }
            alert(warningMsg);
            fetchAndRenderCounselorTimeSlots();
            return;
        }
        if (result.counselor_fully_booked && result.recommended_counselor_id) {
            const confirmed = confirm(`${result.message}\n\nWould you like to schedule with Counselor ${result.recommended_counselor_name} instead?`);
            if (confirmed) {
                document.getElementById('bookingCounselor').value = result.recommended_counselor_id;
                fetchAndRenderCounselorTimeSlots();
                return;
            }
        } else {
            alert('Booking Failed: ' + result.message);
        }
    }
}

/**
 * Load student appointments list
 */
async function loadStudentAppointments() {
    const listContainer = document.getElementById('studentAppointmentsList');
    if (!listContainer) return;

    listContainer.innerHTML = '<div class="glass-card"><p>Loading appointments...</p></div>';

    const result = await apiRequest('student/get_appointments.php');
    if (result.success && result.data.length > 0) {
        listContainer.innerHTML = '';
        result.data.forEach(apt => {
            let statusBadge = `<span style="color: var(--warning)">Pending</span>`;
            if (apt.status === 'accepted') {
                statusBadge = `<span style="color: var(--success); font-weight: 600;">Accepted</span>`;
            } else if (apt.status === 'rejected') {
                statusBadge = `<span style="color: var(--danger)">Rejected</span>`;
            } else if (apt.status === 'completed') {
                statusBadge = `<span style="color: var(--text-muted)">Completed</span>`;
            } else if (apt.status === 'missing') {
                statusBadge = `<span style="color: var(--danger); font-weight: 600;">Missing</span>`;
            }

            // Determine which action button to show based on session type
            let actionBtn = '';
            if (apt.status === 'accepted') {
                if (apt.session_type === 'video') {
                    const _cname = (apt.counselor_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    actionBtn = `<button class="btn-primary" onclick="openVideoConsultationRoom('${apt.meeting_link}', ${apt.id}, '${_cname}')" style="padding: 6px 12px; font-size: 13px; margin-top: 10px; width: auto; display: inline-block;">Launch Video Consultation</button>`;
                } else if (apt.session_type === 'chat') {
                    // Open chat with counselor
                    actionBtn = `<button class="btn-primary" style="padding: 6px 12px; font-size: 13px; margin-top: 10px;" onclick="startSession(${apt.id}); switchTabToChatWithPartner(${apt.counselor_id}, '${apt.counselor_name}')">Open Chat</button>`;
                } else if (apt.session_type === 'group') {
                    actionBtn = `
                        <div style="display: flex; gap: 8px; margin-top: 10px;">
                            <button class="btn-primary" onclick="openVideoConsultationRoom('https://meet.jit.si/mental_support_group_video_discussion', ${apt.id})" style="padding: 6px 12px; font-size: 13px; width: auto;">Join Group Video</button>
                            <button class="btn-secondary" style="padding: 6px 12px; font-size: 13px;" onclick="startSession(${apt.id}); switchTab('discussionTab')">Join Group Chat</button>
                        </div>
                    `;
                }
            }

            listContainer.innerHTML += `
                <div class="glass-card" style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                        <div>
                            <h3 style="color: var(--primary);">${apt.counselor_name}</h3>
                            <p style="font-size: 14px; color: var(--text-secondary);">${apt.date} at ${apt.time}</p>
                        </div>
                        <div>${statusBadge}</div>
                    </div>
                    ${actionBtn}
                </div>
            `;
        });
    } else {
        listContainer.innerHTML = '<div class="glass-card"><p style="color: var(--text-secondary);">No appointments scheduled yet.</p></div>';
    }
}

/**
 * Start GAD-7 Self-Assessment Wizard
 */
// Assessment metadata and mapping
const AssessmentMetadata = {
    anxiety: { name: 'Anxiety', maxScore: 9 },
    depression: { name: 'Depression', maxScore: 9 },
    bipolar: { name: 'Bipolar Screen', maxScore: 9 },
    ptsd: { name: 'PTSD Screen', maxScore: 6 },
    stress: { name: 'Stress', maxScore: 6 },
    self_esteem: { name: 'Self-Esteem', maxScore: 6 },
    combined: { name: 'Combined Assessment', maxScore: 45 }
};

function renderWellnessScale(score, maxScore = 45) {
    const percentage = Math.min(100, Math.max(0, (score / maxScore) * 100));
    
    let statusLabel = '';
    let color = '';
    let recommendation = '';
    
    if (score <= 11) {
        statusLabel = 'Mentally Well';
        color = '#38bdf8'; // Light Blue (sky blue)
        recommendation = 'You show a healthy mental wellness profile. Keep maintaining a balanced routine, getting good sleep, and practicing self-care.';
    } else if (score <= 22) {
        statusLabel = 'Mild Distress';
        color = '#eab308'; // Yellow
        recommendation = 'You are experiencing mild emotional or physical distress. Incorporating regular breaks, mindfulness, or sharing your feelings can help.';
    } else if (score <= 33) {
        statusLabel = 'Moderate Distress';
        color = '#f97316'; // Orange
        recommendation = 'You are experiencing moderate distress. You might benefit from reaching out to our community boards or speaking to a peer.';
    } else {
        statusLabel = 'Critical Distress (Must See Counselor)';
        color = '#ef4444'; // Bright Red
        recommendation = 'Critical: You are experiencing high levels of distress. Please schedule a priority session with one of our licensed counselors immediately.';
    }
    
    return {
        percentage,
        statusLabel,
        color,
        recommendation
    };
}

/**
 * Start Self-Assessment Wizard for selected type
 */
async function startSelfAssessment(type = 'combined') {
    const selectionGrid = document.getElementById('assessmentSelectionGrid');
    const container = document.getElementById('assessmentWizardContainer');
    if (!container) return;

    if (selectionGrid) selectionGrid.style.display = 'none';
    container.style.display = 'block';
    container.innerHTML = '<p>Loading questionnaire...</p>';

    App.assessmentType = type;

    const result = await apiRequest(`assessment/get_questions.php?type=${type}`);
    if (result.success) {
        App.assessmentQuestions = result.data.questions;
        App.assessmentAnswers = {};
        App.currentQuestionIndex = 0;
        App.assessmentTitle = result.data.title;
        App.assessmentSubtitle = result.data.subtitle;
        renderAssessmentQuestion();
    } else {
        container.innerHTML = `
            <p>Failed to load questions. Please check server connection.</p>
            <button class="btn-secondary" style="margin-top: 10px;" onclick="resetToAssessmentSelection()">Back to Selection</button>
        `;
    }
}

/**
 * Return to the selection grid of all assessments
 */
function resetToAssessmentSelection() {
    const selectionGrid = document.getElementById('assessmentSelectionGrid');
    const container = document.getElementById('assessmentWizardContainer');
    if (selectionGrid) selectionGrid.style.display = 'block';
    if (container) {
        container.style.display = 'none';
        container.innerHTML = '';
    }
}

/**
 * Render Question card in Wizard
 */
function renderAssessmentQuestion() {
    const container = document.getElementById('assessmentWizardContainer');
    if (!container) return;

    const totalQuestions = App.assessmentQuestions.length;
    const qIndex = App.currentQuestionIndex;
    
    // Check if we finished all questions
    if (qIndex >= totalQuestions) {
        container.innerHTML = `
            <div class="glass-card text-center" style="padding: 40px 24px;">
                <h2 style="margin-bottom: 16px; color: var(--primary);">Assessment Complete</h2>
                <p style="color: var(--text-secondary); margin-bottom: 24px;">All answers recorded. Ready to calculate your score.</p>
                <div style="display: flex; justify-content: center; gap: 16px;">
                    <button class="btn-secondary" onclick="resetToAssessmentSelection()">Cancel</button>
                    <button class="btn-primary" onclick="submitSelfAssessment()">Submit Assessment</button>
                </div>
            </div>
        `;
        return;
    }

    const currentQ = App.assessmentQuestions[qIndex];
    const progressPercent = ((qIndex) / totalQuestions) * 100;

    let categoryBadge = '';
    if (currentQ.disorder) {
        const disNames = {
            anxiety: 'Anxiety Symptoms',
            depression: 'Depression Symptoms',
            bipolar: 'Mood Stability (Bipolar)',
            ptsd: 'Trauma & PTSD Symptoms',
            stress: 'Stress & Coping',
            self_esteem: 'Self-Esteem & Self-Worth'
        };
        const displayName = disNames[currentQ.disorder] || currentQ.disorder;
        categoryBadge = `<span style="display:inline-block; padding: 4px 10px; border-radius: 20px; background: rgba(9, 132, 227, 0.15); color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">${displayName}</span>`;
    }

    // Standard options (0: Not at all, 1: Several days, 2: More than half, 3: Nearly every day)
    const options = [
        { val: 0, label: 'Not at all' },
        { val: 1, label: 'Several days' },
        { val: 2, label: 'More than half the days' },
        { val: 3, label: 'Nearly every day' }
    ];

    let optionsHtml = '';
    options.forEach(opt => {
        const isSelected = App.assessmentAnswers[currentQ.id] === opt.val ? 'selected' : '';
        optionsHtml += `
            <div class="assessment-option ${isSelected}" onclick="recordAssessmentAnswer(${currentQ.id}, ${opt.val})">
                <div class="option-bullet"></div>
                <div>${opt.label}</div>
            </div>
        `;
    });

    container.innerHTML = `
        <div class="assessment-wizard">
            <div class="assessment-progress">
                <div class="assessment-progress-bar" style="width: ${progressPercent}%"></div>
            </div>
            <div class="glass-card assessment-q-card">
                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">Question ${qIndex + 1} of ${totalQuestions} (${App.assessmentTitle})</div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <h3 style="font-size: 15px; font-weight: 500; color: var(--text-secondary); margin: 0;">${App.assessmentSubtitle}</h3>
                    ${categoryBadge}
                </div>
                <h2 style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin-bottom: 24px;">"${currentQ.text}"</h2>
                <div class="assessment-options">
                    ${optionsHtml}
                </div>
                <div style="margin-top: 24px; display: flex; justify-content: space-between;">
                    <button class="btn-secondary" onclick="prevAssessmentQuestion()" ${qIndex === 0 ? 'disabled' : ''}>Back</button>
                    <button class="btn-secondary" onclick="resetToAssessmentSelection()">Exit Wizard</button>
                </div>
            </div>
        </div>
    `;
}

/**
 * Handle option selection
 */
function recordAssessmentAnswer(qId, val) {
    App.assessmentAnswers[qId] = val;
    // Auto-advance to next question after small delay for animation feel
    setTimeout(() => {
        App.currentQuestionIndex++;
        renderAssessmentQuestion();
    }, 250);
}

/**
 * Go back a question
 */
function prevAssessmentQuestion() {
    if (App.currentQuestionIndex > 0) {
        App.currentQuestionIndex--;
        renderAssessmentQuestion();
    }
}

/**
 * Submit answers to API
 */
async function submitSelfAssessment() {
    // Check all answers are present
    const answersList = [];
    App.assessmentQuestions.forEach(q => {
        answersList.push(App.assessmentAnswers[q.id]);
    });

    const result = await apiRequest('assessment/submit_assessment.php', 'POST', {
        assessment_type: App.assessmentType,
        answers: answersList
    });

    const container = document.getElementById('assessmentWizardContainer');
    if (!container) return;

    if (result.success) {
        App.hasCompletedAssessment = true;

        if (result.data.is_critical) {
            App.urgentBooking = {
                isUrgent: true,
                disorder: result.data.urgency_disorder || ''
            };
            alert("🚨 IMMEDIATE ATTENTION REQUIRED\n\nYour self-assessment indicates a critical level of distress.\nWe are moving you straight to consultation booking to schedule an immediate session with a specialized counselor.");
            switchTab('appointmentsTab');
            loadStudentAssessmentsHistory();
            return;
        }

        // Assessment complete — alert & move straight to booking as requested
        alert("✅ Self-Assessment Complete!\n\nYour risk level score has been recorded. Moving straight to consultation booking now...");
        switchTab('appointmentsTab');
        loadStudentAssessmentsHistory();
        return;
    } else {
        alert('Submission failed: ' + result.message);
        renderAssessmentQuestion();
    }
}

/**
 * Build a counselor recommendation card HTML string
 * @param {Object|null} counselor - Counselor object from API or null
 */
function buildCounselorRecommendationHtml(counselor) {
    if (counselor) {
        const initials = counselor.name
            ? counselor.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)
            : '?';
        return `
            <div class="counselor-recommendation">
                <div class="counselor-rec-header">
                    <span class="counselor-rec-icon">🧑‍⚕️</span>
                    <span class="counselor-rec-label">Recommended Counselor</span>
                </div>
                <div class="counselor-rec-body">
                    <div class="counselor-rec-avatar">${initials}</div>
                    <div class="counselor-rec-info">
                        <h4 class="counselor-rec-name">${counselor.name}</h4>
                        <span class="counselor-rec-specialty">${counselor.specialty}</span>
                        <p class="counselor-rec-bio">${counselor.bio || 'Experienced mental health professional ready to support you.'}</p>
                    </div>
                </div>
                <p class="counselor-rec-cta">Based on your results, we recommend booking a session with this counselor.</p>
            </div>
        `;
    } else {
        return `
            <div class="counselor-recommendation counselor-rec-fallback">
                <span class="counselor-rec-icon">💬</span>
                <p>Our team will review your results and assign a suitable counselor to you shortly.</p>
            </div>
        `;
    }
}

/**
 * Load student assessment records
 */
async function loadStudentAssessmentsHistory() {
    const listContainer = document.getElementById('studentAssessmentsList');
    if (!listContainer) return;

    listContainer.innerHTML = '<p>Loading history...</p>';

    const result = await apiRequest('student/get_assessments.php');
    if (result.success && result.data.length > 0) {
        listContainer.innerHTML = '';
        result.data.forEach(asmt => {
            const date = new Date(asmt.completed_at).toLocaleDateString();
            const meta = AssessmentMetadata[asmt.assessment_type] || { name: 'Combined Assessment', maxScore: 37 };

            // Student side: show only wellness scale bar (no diagnostic details)
            let wellnessBarHtml = '';
            if (asmt.assessment_type === 'combined') {
                const wellness = renderWellnessScale(asmt.score, AssessmentMetadata.combined.maxScore);
                wellnessBarHtml = `
                    <div style="margin-top: 10px; border-top: 1px solid var(--glass-border); padding-top: 10px;">
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-secondary); margin-bottom: 4px;">
                            <span>Wellness Level</span>
                            <span style="font-weight: 700; color: ${wellness.color};">${wellness.statusLabel}</span>
                        </div>
                        <div style="width: 100%; background: var(--glass-border); height: 10px; border-radius: 5px; overflow: hidden;">
                            <div style="width: ${wellness.percentage}%; background: ${wellness.color}; height: 100%; border-radius: 5px; transition: width 0.6s ease;"></div>
                        </div>
                        <p style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">${wellness.recommendation}</p>
                    </div>
                `;
            }

            listContainer.innerHTML += `
                <div style="padding: 12px 16px; border-bottom: 1px solid var(--glass-border);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h4 style="font-weight: 600; font-size: 14px;">${meta.name}</h4>
                            <span style="font-size: 12px; color: var(--text-secondary);">${date}</span>
                        </div>
                        <div style="font-size: 14px; font-weight: 700; color: var(--primary);">
                            ${asmt.assessment_type === 'combined' ? 'Completed' : asmt.score + ' / ' + meta.maxScore}
                        </div>
                    </div>
                    ${wellnessBarHtml}
                </div>
            `;
        });
    } else {
        listContainer.innerHTML = '<p style="color: var(--text-secondary);">No assessment logs found. Take an assessment to view history.</p>';
    }
}

/**
 * Load recovery stories board
 */
async function loadStoriesFeed() {
    const board = document.getElementById('storiesBoard');
    if (!board) return;

    board.innerHTML = '<p>Loading shared stories...</p>';

    const result = await apiRequest('stories/get_stories.php');
    if (result.success && result.data.length > 0) {
        board.innerHTML = '';
        let storiesHtml = '';
        result.data.forEach(story => {
            const date = new Date(story.created_at).toLocaleDateString();
            storiesHtml += `
                <div class="glass-card story-card">
                    <div class="story-header">
                        <span class="story-author">Shared by: ${story.student_name}</span>
                        <h3 class="story-title">${story.title}</h3>
                    </div>
                    <p class="story-content">${story.content}</p>
                    <div class="story-footer">
                        <span>Posted on: ${date}</span>
                    </div>
                </div>
            `;
        });
        board.innerHTML = `<div class="stories-grid">${storiesHtml}</div>`;
    } else {
        board.innerHTML = '<p style="color: var(--text-secondary);">No stories have been posted yet. Be the first to share your recovery story!</p>';
    }
}

/**
 * Submit story sharing form
 */
async function handleStoryShare(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const result = await apiRequest('stories/create_story.php', 'POST', formData);
    if (result.success) {
        alert(result.message);
        form.reset();
        loadStoriesFeed();
    } else {
        alert('Failed to post story: ' + result.message);
    }
}


/* ==========================================================================
   COUNSELOR PORTAL LOGIC
   ========================================================================== */

/**
 * Load counselor appointments
 */
async function loadCounselorAppointments() {
    const pendingList = document.getElementById('pendingRequestsList');
    const scheduledList = document.getElementById('scheduledSessionsList');

    if (pendingList) pendingList.innerHTML = '<p>Loading requests...</p>';
    if (scheduledList) scheduledList.innerHTML = '<p>Loading sessions...</p>';

    const result = await apiRequest('counselor/get_appointments.php');
    if (result.success) {
        const pending = result.data.filter(a => a.status === 'pending');
        const active = result.data.filter(a => a.status === 'accepted');

        // Render Pending
        if (pendingList) {
            if (pending.length > 0) {
                pendingList.innerHTML = '';
                pending.forEach(apt => {
                    let priorityBadge = '';
                    let cardBorder = '4px solid #0d9488';

                    if (apt.priority === 'High' || apt.is_urgent == 1) {
                        cardBorder = '4px solid #ef4444';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    🔥 High Priority (${apt.score_percent || 90}% Assessment Score)
                                </span>
                            </div>
                        `;
                    } else if (apt.priority === 'Medium') {
                        cardBorder = '4px solid #f59e0b';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    ⚡ Medium Priority (${apt.score_percent}% Assessment Score)
                                </span>
                            </div>
                        `;
                    } else {
                        cardBorder = '4px solid #0d9488';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(13,148,136,0.12); color: #0d9488; border: 1px solid rgba(13,148,136,0.3); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    ℹ️ Average Priority (${apt.score_percent || 0}% Assessment Score)
                                </span>
                            </div>
                        `;
                    }

                    const isHighRisk = (apt.priority === 'High' || apt.is_urgent == 1);
                    const _sname = (apt.student_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    
                    let rescheduleBtn = '';
                    if (isHighRisk) {
                        rescheduleBtn = `<button class="btn-secondary" style="padding: 6px 12px; font-size: 13px; border-color: rgba(239,68,68,0.4); color: #ef4444; opacity: 0.85; cursor: not-allowed;" onclick="alert('⚠️ High Risk Restriction:\\n\\nStudents with high-risk assessment scores (≥90% or urgent distress) cannot be rescheduled. They must be attended to immediately at their requested time slot.')">🚫 Reschedule Blocked (High Risk)</button>`;
                    } else {
                        rescheduleBtn = `<button class="btn-secondary" style="padding: 6px 12px; font-size: 13px; border-color: var(--warning); color: var(--warning);" onclick="openRescheduleModal('${apt.id}', '${_sname}', '${apt.date}', '${apt.time}', false)">Reschedule / Reassign</button>`;
                    }

                    pendingList.innerHTML += `
                        <div class="glass-card" style="margin-bottom: 16px; border-left: ${cardBorder};">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                <div>
                                    <h3 style="color: var(--primary); cursor: pointer; margin-bottom: 4px;" onclick="openStudentDetailsModal('${apt.student_id}')">${apt.student_name}</h3>
                                    ${priorityBadge}
                                    <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 4px;">🗓️ Requested: <strong>${apt.date} at ${apt.time}</strong></p>
                                    <span style="font-size: 12px; color: var(--text-muted); text-decoration: underline; cursor: pointer;" onclick="openStudentDetailsModal('${apt.student_id}')">View full student record</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <button class="btn-primary" style="padding: 6px 12px; font-size: 13px;" onclick="updateAppointment('${apt.id}', 'accepted')">Approve Request</button>
                                ${rescheduleBtn}
                            </div>
                        </div>
                    `;
                });
            } else {
                pendingList.innerHTML = '<p style="color: var(--text-secondary);">No pending appointment requests.</p>';
            }
        }

        // Render Active Scheduled
        if (scheduledList) {
            if (active.length > 0) {
                scheduledList.innerHTML = '';
                active.forEach(apt => {
                    let priorityBadge = '';
                    let cardBorder = '4px solid #0d9488';

                    if (apt.priority === 'High' || apt.is_urgent == 1) {
                        cardBorder = '4px solid #ef4444';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    🔥 High Priority (${apt.score_percent || 90}% Assessment Score)
                                </span>
                            </div>
                        `;
                    } else if (apt.priority === 'Medium') {
                        cardBorder = '4px solid #f59e0b';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    ⚡ Medium Priority (${apt.score_percent}% Assessment Score)
                                </span>
                            </div>
                        `;
                    } else {
                        cardBorder = '4px solid #0d9488';
                        priorityBadge = `
                            <div style="margin-bottom: 8px;">
                                <span style="background: rgba(13,148,136,0.12); color: #0d9488; border: 1px solid rgba(13,148,136,0.3); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    ℹ️ Average Priority (${apt.score_percent || 0}% Assessment Score)
                                </span>
                            </div>
                        `;
                    }

                    let counselorActionBtn = '';
                    if (apt.session_type === 'video') {
                        const _sname = (apt.student_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                        counselorActionBtn = `<button class="btn-primary" onclick="openVideoConsultationRoom('${apt.meeting_link}', ${apt.id}, '${_sname}')" style="padding: 6px 12px; font-size: 13px;">Launch Consultation Room</button>`;
                    } else if (apt.session_type === 'chat') {
                        counselorActionBtn = `<button class="btn-primary" style="padding: 6px 12px; font-size: 13px; color: #fff; background: var(--primary); border-color: var(--primary);" onclick="startSession(${apt.id}); switchTabToChatWithPartner('${apt.student_id}', '${apt.student_name}')">Send Message</button>`;
                    }

                    scheduledList.innerHTML += `
                        <div class="glass-card" style="margin-bottom: 16px; border-left: ${cardBorder};">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                <div>
                                    <h3 style="margin-bottom: 4px;">${apt.student_name}</h3>
                                    ${priorityBadge}
                                    <p style="font-size: 14px; color: var(--text-secondary);">🗓️ Scheduled: <strong>${apt.date} at ${apt.time}</strong></p>
                                </div>
                                <button class="btn-primary" style="padding: 6px 12px; font-size: 13px; color: #fff; background: var(--success); border-color: var(--success);" onclick="openCompleteSessionModal('${apt.id}', '${apt.student_name}')">Mark Completed</button>
                            </div>
                            <div style="display: flex; gap: 12px; align-items: center; margin-top: 10px;">
                                 ${counselorActionBtn}
                            </div>
                        </div>
                    `;
                });
            } else {
                scheduledList.innerHTML = '<p style="color: var(--text-secondary);">No upcoming scheduled sessions.</p>';
            }
        }

        // Render Completed / Historical sessions
        const completedList = document.getElementById('completedSessionsList');
        if (completedList) {
            const completed = result.data.filter(a => a.status === 'completed');
            if (completed.length > 0) {
                completedList.innerHTML = '';
                completed.forEach(apt => {
                    const hasNote = apt.counselor_progress_note && apt.counselor_progress_note.length > 0;
                    completedList.innerHTML += `
                        <div class="glass-card" style="margin-bottom: 12px; border-left: 3px solid var(--success); opacity: 0.92;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                                <div>
                                    <h3 style="color: var(--text-primary); font-size: 15px;">${apt.student_name}</h3>
                                    <p style="font-size: 13px; color: var(--text-secondary);">${apt.date} at ${apt.time} &mdash; ${apt.session_type || 'session'}</p>
                                    ${hasNote ? '<span style="font-size: 11px; color: var(--success);">&#10003; Progress report submitted</span>' : '<span style="font-size: 11px; color: var(--warning);">&#9888; No progress report yet</span>'}
                                </div>
                                <button class="btn-secondary" style="padding: 5px 14px; font-size: 12px; border-color: var(--primary); color: var(--primary); flex-shrink: 0;" onclick="openAiSummaryModal('${apt.id}')">
                                    📝 ${hasNote ? 'Update Progress Report' : 'Write Progress Report'}
                                </button>
                            </div>
                        </div>
                    `;
                });
            } else {
                completedList.innerHTML = '<p style="color: var(--text-secondary); font-size: 13px;">No completed sessions yet.</p>';
            }
        }
    }
}


/**
 * Accept/Reject appointment API trigger
 */
async function updateAppointment(aptId, status) {
    const result = await apiRequest('counselor/update_appointment.php', 'POST', {
        appointment_id: aptId,
        status: status
    });
    
    if (result.success) {
        alert(result.message);
        loadCounselorAppointments();
    } else {
        alert('Update Failed: ' + result.message);
    }
}

let currentCompletingAptId = null;

function openCompleteSessionModal(aptId, studentName) {
    currentCompletingAptId = aptId;
    const nameEl = document.getElementById('completeStudentName');
    if (nameEl) nameEl.innerText = studentName;
    openModal('completeSessionModal');
}

async function submitSessionCompletion(recommendGroup) {
    if (!currentCompletingAptId) return;
    
    closeModal('completeSessionModal');
    
    const result = await apiRequest('counselor/update_appointment.php', 'POST', {
        appointment_id: parseInt(currentCompletingAptId),
        status: 'completed',
        recommend_group: recommendGroup
    });
    
    const completedAptId = currentCompletingAptId;
    currentCompletingAptId = null;

    if (result.success) {
        loadCounselorAppointments();
        // Prompt counselor to write the progress report
        setTimeout(() => {
            if (confirm('Session marked as completed.\n\nWould you like to write a progress report for the lecturer?')) {
                openAiSummaryModal(completedAptId);
            }
        }, 300);
    } else {
        alert('Update Failed: ' + result.message);
    }
}

/**
 * Open Reschedule / Reassign Modal
 * Fetches list of active counselors, pre-populates current appointment data.
 */
async function openRescheduleModal(appointmentId, studentName, currentDate, currentTime, isHighRisk = false) {
    if (isHighRisk) {
        alert("⚠️ High Risk Restriction:\n\nStudents classified as High Risk (≥90% assessment score or urgent distress) cannot be rescheduled. They must be attended to immediately at their requested time slot.");
        return;
    }

    const modal = document.getElementById('rescheduleModal');
    if (!modal) return;

    const rescheduleDateInput = document.getElementById('rescheduleDate');
    if (rescheduleDateInput) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        rescheduleDateInput.min = `${yyyy}-${mm}-${dd}`;
    }

    // Store the appointment id for submission
    document.getElementById('rescheduleAppointmentId').value = appointmentId;
    document.getElementById('rescheduleStudentLabel').innerText = studentName;
    document.getElementById('rescheduleDate').value = currentDate;
    document.getElementById('rescheduleTime').value = currentTime;

    // Fetch available counselors
    const counselorDropdown = document.getElementById('rescheduleCounselor');
    counselorDropdown.innerHTML = '<option value="">Loading counselors...</option>';

    const result = await apiRequest('counselor/get_appointments.php?action=list_counselors');
    if (result.success) {
        const counselors = result.data.counselors || result.data;
        const active = counselors.filter(c => c.status === 'active');
        counselorDropdown.innerHTML = '';

        // Default option: keep current counselor (self)
        counselorDropdown.innerHTML += `<option value="${App.user.id}">Myself (Keep Current Counselor)</option>`;

        active.forEach(c => {
            if (String(c.user_id) !== String(App.user.id)) {
                counselorDropdown.innerHTML += `<option value="${c.user_id}">${c.name} (${c.specialty})</option>`;
            }
        });
    } else {
        counselorDropdown.innerHTML = '<option value="">Failed to load counselors</option>';
    }

    openModal('rescheduleModal');
}

/**
 * Handle Reschedule / Reassign form submission
 */
async function handleReschedule(event) {
    event.preventDefault();

    const appointmentId = document.getElementById('rescheduleAppointmentId').value;
    const newCounselorId = document.getElementById('rescheduleCounselor').value;
    const newDate = document.getElementById('rescheduleDate').value;
    const newTime = document.getElementById('rescheduleTime').value;

    if (!appointmentId || !newCounselorId || !newDate || !newTime) {
        alert('Please fill in all fields.');
        return;
    }

    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.innerText = 'Processing...';
    submitBtn.disabled = true;

    const result = await apiRequest('counselor/reschedule_appointment.php', 'POST', {
        appointment_id: parseInt(appointmentId),
        new_counselor_id: parseInt(newCounselorId),
        new_date: newDate,
        new_time: newTime
    });

    submitBtn.innerText = 'Confirm Reschedule';
    submitBtn.disabled = false;

    if (result.success) {
        alert(result.message);
        closeModal('rescheduleModal');
        loadCounselorAppointments();
    } else {
        if (result.high_risk_blocked) {
            alert('⚠️ High Risk Restriction:\n\n' + result.message);
            closeModal('rescheduleModal');
            loadCounselorAppointments();
            return;
        }
        alert('Reschedule Failed: ' + result.message);
    }
}

/**
 * Open Student details modal (Profile + Assessment scores)
 */
async function openStudentDetailsModal(studentId) {
    const container = document.getElementById('studentAuditDetails');
    if (!container) return;

    container.innerHTML = '<p>Loading student information...</p>';
    openModal('studentAuditModal');

    const result = await apiRequest(`counselor/student_details.php?student_id=${studentId}`);
    if (result.success) {
        const profile = result.data.profile;
        const history = result.data.assessments;

        let historyHtml = '<p style="color: var(--text-secondary);">No self-assessment files recorded.</p>';
        if (history && history.length > 0) {
            historyHtml = '<div style="max-height: 250px; overflow-y: auto;">';
            history.forEach(h => {
                const date = new Date(h.completed_at).toLocaleDateString();
                const meta = AssessmentMetadata[h.assessment_type] || { name: 'Combined Assessment', maxScore: 37 };
                
                let detailsHtml = '';
                if (h.assessment_type === 'combined' && h.details) {
                    let detailsObj = null;
                    try {
                        detailsObj = typeof h.details === 'string' ? JSON.parse(h.details) : h.details;
                    } catch(e) {}

                    if (detailsObj) {
                        // Counselor view: full diagnostic breakdown with color-coded severity
                        detailsHtml = '<div style="margin-top: 12px; border-top: 1px solid var(--glass-border); padding-top: 10px;">';
                        detailsHtml += '<div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Diagnostic Breakdown</div>';
                        detailsHtml += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">';
                        for (const [dis, info] of Object.entries(detailsObj)) {
                            const dispName = AssessmentMetadata[dis] ? AssessmentMetadata[dis].name : dis;
                            const disMax = AssessmentMetadata[dis] ? AssessmentMetadata[dis].maxScore : 10;
                            let statusColor = '#22c55e';
                            let severityLabel = info.category || 'Normal';
                            if (dis === 'self_esteem') {
                                if (info.score <= 4) statusColor = '#ef4444';
                                else if (info.score <= 10) statusColor = '#f59e0b';
                            } else {
                                const ratio = info.score / disMax;
                                if (ratio >= 0.6) statusColor = '#ef4444';
                                else if (ratio >= 0.3) statusColor = '#f59e0b';
                            }
                            const barPct = Math.min(100, Math.round((info.score / disMax) * 100));
                            detailsHtml += `
                                <div style="background: rgba(255,255,255,0.04); border-radius: 8px; padding: 10px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <span style="font-size: 12px; font-weight: 700; color: var(--text-primary);">${dispName}</span>
                                        <span style="font-size: 11px; font-weight: 700; color: ${statusColor};">${info.score}/${disMax}</span>
                                    </div>
                                    <div style="width: 100%; background: rgba(255,255,255,0.1); height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 4px;">
                                        <div style="width: ${barPct}%; background: ${statusColor}; height: 100%; border-radius: 3px;"></div>
                                    </div>
                                    <span style="font-size: 10px; color: ${statusColor}; font-weight: 600;">${severityLabel}</span>
                                </div>
                            `;
                        }
                        detailsHtml += '</div></div>';
                    }
                }

                let scoreColor = 'var(--success)';
                if (h.assessment_type === 'self_esteem') {
                    if (h.score <= 4) scoreColor = 'var(--danger)';
                    else if (h.score <= 10) scoreColor = 'var(--warning)';
                } else if (h.assessment_type !== 'combined') {
                    const ratio = h.score / meta.maxScore;
                    if (ratio >= 0.6) scoreColor = 'var(--danger)';
                    else if (ratio >= 0.3) scoreColor = 'var(--warning)';
                }

                historyHtml += `
                    <div style="border-bottom: 1px solid var(--glass-border); padding: 12px 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                            <div>
                                <div style="font-weight: 600; font-size: 13px; color: var(--text-primary);">${meta.name}</div>
                                <div style="font-size: 12px; color: var(--text-secondary);">${h.category}</div>
                                <div style="font-size: 11px; color: var(--text-muted);">${date}</div>
                            </div>
                            <div style="font-weight: 700; color: ${h.assessment_type === 'combined' ? 'var(--primary)' : scoreColor}; font-size: 15px;">
                                ${h.assessment_type === 'combined' ? 'Consolidated' : h.score + ' / ' + meta.maxScore}
                            </div>
                        </div>
                        ${detailsHtml}
                    </div>
                `;
            });
            historyHtml += '</div>';
        }

        container.innerHTML = `
            <div style="margin-bottom: 20px; padding: 16px; background: rgba(var(--primary-rgb, 99,102,241), 0.08); border-radius: 12px; border-left: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; color: #fff; flex-shrink: 0;">
                        ${profile.name ? profile.name.charAt(0).toUpperCase() : 'S'}
                    </div>
                    <div>
                        <h3 style="color: var(--primary); font-size: 20px; margin: 0 0 2px;">${profile.name}</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">Student Health Record</span>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; color: var(--text-secondary);">
                    <div><strong style="color: var(--text-primary);">Department:</strong> ${profile.department || 'N/A'}</div>
                    <div><strong style="color: var(--text-primary);">Student ID:</strong> ${profile.card_num || 'N/A'}</div>
                    <div><strong style="color: var(--text-primary);">Phone:</strong> ${profile.phone || 'N/A'}</div>
                </div>
                ${profile.bio ? `<div style="margin-top: 10px; padding: 8px; background: rgba(255,255,255,0.03); border-radius: 8px; font-style: italic; font-size: 13px; color: var(--text-secondary);">&ldquo;${profile.bio}&rdquo;</div>` : ''}
            </div>
            <div>
                <h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 12px; font-weight: 600;">Self-Assessment History &amp; Diagnostics</h4>
                ${historyHtml}
            </div>
        `;
    } else {
        container.innerHTML = '<p style="color: var(--danger);">Failed to retrieve student records.</p>';
    }
}

/**
 * Route counselor to chat with student
 */
function switchTabToChatWithPartner(userId, name) {
    App.chatPartnerId = userId;
    App.chatPartnerName = name;
    switchTab('chatTab');
}

/**
 * Load all student assessments for counselor dashboard
 */
async function loadCounselorStudentAssessments() {
    const container = document.getElementById('counselorAssessmentsList');
    if (!container) return;

    container.innerHTML = '<p>Loading student assessments...</p>';

    const result = await apiRequest('counselor/get_all_assessments.php');
    if (result.success && result.data.length > 0) {
        container.innerHTML = '';

        result.data.forEach(asmt => {
            const date = new Date(asmt.completed_at).toLocaleDateString();
            const time = new Date(asmt.completed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const meta = AssessmentMetadata[asmt.assessment_type] || { name: 'Combined Assessment', maxScore: 37 };

            // Build diagnostic breakdown for combined assessments
            let detailsHtml = '';
            if (asmt.assessment_type === 'combined' && asmt.details) {
                let detailsObj = null;
                try {
                    detailsObj = typeof asmt.details === 'string' ? JSON.parse(asmt.details) : asmt.details;
                } catch(e) {}

                if (detailsObj) {
                    detailsHtml = '<div style="margin-top: 12px; border-top: 1px solid var(--glass-border); padding-top: 10px;">';
                    detailsHtml += '<div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Diagnostic Breakdown</div>';
                    detailsHtml += '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;">';
                    for (const [dis, info] of Object.entries(detailsObj)) {
                        const dispName = AssessmentMetadata[dis] ? AssessmentMetadata[dis].name : dis;
                        const disMax = AssessmentMetadata[dis] ? AssessmentMetadata[dis].maxScore : 10;
                        let statusColor = '#22c55e';
                        if (dis === 'self_esteem') {
                            if (info.score <= 4) statusColor = '#ef4444';
                            else if (info.score <= 10) statusColor = '#f59e0b';
                        } else {
                            const ratio = info.score / disMax;
                            if (ratio >= 0.6) statusColor = '#ef4444';
                            else if (ratio >= 0.3) statusColor = '#f59e0b';
                        }
                        const barPct = Math.min(100, Math.round((info.score / disMax) * 100));
                        detailsHtml += `
                            <div style="background: rgba(255,255,255,0.04); border-radius: 8px; padding: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                                    <span style="font-size: 11px; font-weight: 700; color: var(--text-primary);">${dispName}</span>
                                    <span style="font-size: 10px; font-weight: 700; color: ${statusColor};">${info.score}/${disMax}</span>
                                </div>
                                <div style="width: 100%; background: rgba(255,255,255,0.1); height: 5px; border-radius: 3px; overflow: hidden; margin-bottom: 3px;">
                                    <div style="width: ${barPct}%; background: ${statusColor}; height: 100%; border-radius: 3px;"></div>
                                </div>
                                <span style="font-size: 9px; color: ${statusColor}; font-weight: 600;">${info.category || 'Normal'}</span>
                            </div>
                        `;
                    }
                    detailsHtml += '</div></div>';
                }
            }

            // Build Priority Badge
            let priorityBadge = '';
            let cardBorder = '4px solid #0d9488';
            if (asmt.priority === 'High') {
                cardBorder = '4px solid #ef4444';
                priorityBadge = `<span style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 8px;">🔥 High Priority (${asmt.score_percent}%)</span>`;
            } else if (asmt.priority === 'Medium') {
                cardBorder = '4px solid #f59e0b';
                priorityBadge = `<span style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 8px;">⚡ Medium Priority (${asmt.score_percent}%)</span>`;
            } else {
                cardBorder = '4px solid #0d9488';
                priorityBadge = `<span style="background: rgba(13,148,136,0.12); color: #0d9488; border: 1px solid rgba(13,148,136,0.3); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 8px;">ℹ️ Average Priority (${asmt.score_percent}%)</span>`;
            }

            container.innerHTML += `
                <div class="glass-card" style="margin-bottom: 16px; padding: 20px; border-left: ${cardBorder};">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; color: #fff; flex-shrink: 0;">
                                ${asmt.student_name ? asmt.student_name.charAt(0).toUpperCase() : 'S'}
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <h3 style="font-size: 16px; font-weight: 700; color: var(--primary); margin: 0; cursor: pointer;" onclick="openStudentDetailsModal('${asmt.student_id}')">${asmt.student_name}</h3>
                                    ${priorityBadge}
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">${asmt.department || 'N/A'} &bull; ${asmt.card_num || 'N/A'}</div>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 12px; color: var(--text-muted);">${date} at ${time}</div>
                            <div style="font-size: 13px; font-weight: 700; color: ${asmt.priority === 'High' ? '#ef4444' : 'var(--primary)'}; margin-top: 4px;">${meta.name}</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">${asmt.category || 'N/A'}</div>
                        </div>
                    </div>
                    ${detailsHtml}
                    <div style="margin-top: 12px; display: flex; gap: 8px; justify-content: flex-end;">
                        <button class="btn-secondary" style="padding: 5px 12px; font-size: 12px;" onclick="openStudentDetailsModal('${asmt.student_id}')">View Full Record</button>
                        <button class="btn-secondary" style="padding: 5px 12px; font-size: 12px;" onclick="switchTabToChatWithPartner('${asmt.student_id}', '${asmt.student_name}')">Message Student</button>
                    </div>
                </div>
            `;
        });
    } else if (result.success) {
        container.innerHTML = '<p style="color: var(--text-secondary);">No student assessments recorded yet.</p>';
    } else {
        container.innerHTML = '<p style="color: var(--danger);">Failed to load assessments: ' + result.message + '</p>';
    }
}

/* ==========================================================================
   ADMIN PORTAL LOGIC
   ========================================================================== */

/**
 * Load admin metrics and stats
 */
async function loadAdminOverviewStats() {
    const result = await apiRequest('admin/dashboard_stats.php');
    if (result.success) {
        const counts = result.data.counts;
        const statMap = {
            'statStudents':     counts.students,
            'statCounselors':   counts.counselors,
            'statLecturers':    counts.lecturers,
            'statAppointments': counts.appointments,
            'statStories':      counts.stories,
            'statAssessments':  counts.assessments
        };
        for (const [id, val] of Object.entries(statMap)) {
            const el = document.getElementById(id);
            if (el) el.innerText = val ?? 0;
        }
    }
}


/**
 * Action to approve counselor profile
 */
async function approveCounselor(userId) {
    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'approve_counselor',
        user_id: userId
    });

    if (result.success) {
        alert(result.message);
        loadAdminOverviewStats();
    } else {
        alert('Approval failed: ' + result.message);
    }
}

/**
 * Load users listing for Admin (Students or Counselors)
 */
async function loadUsersList(role = 'student') {
    const container = document.getElementById('adminUsersList');
    if (!container) return;

    container.innerHTML = '<p>Loading users...</p>';
    
    // Toggle active link highlights
    document.querySelectorAll('.admin-sub-tab').forEach(b => b.classList.remove('active'));
    const subTabBtn = document.getElementById(`btnShow${role.charAt(0).toUpperCase() + role.slice(1)}s`);
    if (subTabBtn) subTabBtn.classList.add('active');

    const result = await apiRequest(`admin/manage_users.php?role=${role}`);
    if (result.success && result.data.length > 0) {
        container.innerHTML = '';
        let listHtml = `
            <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 16px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--glass-border); color: var(--text-secondary); font-size: 14px;">
                        <th style="padding: 12px 8px;">Name</th>
                        <th style="padding: 12px 8px;">${role === 'student' ? 'Student ID' : (role === 'counselor' ? 'Specialty' : 'Department')}</th>
                        <th style="padding: 12px 8px;">${role === 'lecturer' ? 'Email' : 'Contact'}</th>
                        <th style="padding: 12px 8px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
        `;
        result.data.forEach(user => {
            const subtitle = role === 'student' ? (user.student_id || 'N/A') : (role === 'counselor' ? user.specialty : user.department);
            const contact = role === 'student' ? (user.phone || 'N/A') : (role === 'counselor' ? (user.bio ? (user.bio.substring(0, 40) + '...') : 'N/A') : user.email);
            
            listHtml += `
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                                <td style="padding: 12px 8px; font-weight: 500; cursor:pointer; color:var(--primary);" onclick="openUserDetails('${user.user_id}', '${role}')">${user.name}</td>
                    <td style="padding: 12px 8px; color: var(--text-secondary);">${subtitle}</td>
                    <td style="padding: 12px 8px; color: var(--text-secondary); font-size: 14px;">${contact}</td>
                    <td style="padding: 12px 8px; text-align: right;">
                        <div style="display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap;">
                             <button class="btn-secondary" style="padding: 4px 10px; font-size: 11px;" onclick="openPasswordUpdate('${user.user_id}', '${role}')">Change Password</button>
                             <button class="btn-secondary" style="padding: 4px 8px; font-size: 11px; color: var(--danger); border-color: rgba(239, 68, 68, 0.2);" onclick="deleteUser('${user.user_id}', '${role}')">Remove Account</button>
                        </div>
                    </td>
                </tr>
            `;
        });
        listHtml += `</tbody></table>`;
        container.innerHTML = listHtml;
    } else {
        container.innerHTML = `<p style="color: var(--text-secondary); margin-top: 16px;">No ${role} profiles registered.</p>`;
    }
}

/**
 * Open secure deletion modal for a user account
 */
function deleteUser(userId, currentRole) {
    // Store context on the modal for submission
    document.getElementById('deleteTargetUserId').value = userId;
    document.getElementById('deleteTargetRole').value = currentRole;
    document.getElementById('adminPasswordInput').value = '';
    document.getElementById('deleteReasonInput').value = '';
    openModal('deleteUserModal');
}

/**
 * Open user details modal for admin
 */
async function openUserDetails(userId, role) {
    const result = await apiRequest(`admin/manage_users.php?action=get_details&user_id=${userId}&role=${role}`);
    if (result.success) {
        const user = result.data;
        const contentDiv = document.getElementById('userDetailsContent');
        if (contentDiv) {
            let html = `
                <div style="margin-bottom: 20px; padding: 16px; background: rgba(13,148,136,0.08); border-radius: 12px; border-left: 4px solid var(--primary);">
                    <h3 style="font-size: 18px; font-weight: 700; color: var(--primary); margin: 0 0 6px;">${_escHtml(user.name || 'User Profile')}</h3>
                    <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px;">Email: ${_escHtml(user.email)} &bull; Registered: ${new Date(user.created_at).toLocaleDateString()}</p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; color: var(--text-secondary);">
            `;

            if (role === 'student') {
                html += `<div><strong>Student ID:</strong> ${_escHtml(user.student_id || 'N/A')}</div>`;
                html += `<div><strong>Phone:</strong> ${_escHtml(user.phone || 'N/A')}</div>`;
                html += `<div><strong>Department:</strong> ${_escHtml(user.department || 'N/A')}</div>`;
                html += `<div><strong>University:</strong> ${_escHtml(user.university || 'N/A')}</div>`;
                if (user.bio) {
                    html += `<div style="grid-column: 1 / -1; margin-top: 6px; font-style: italic;">&ldquo;${_escHtml(user.bio)}&rdquo;</div>`;
                }
                html += `</div></div>`;

                // Render Self-Assessments History
                html += `<div style="margin-top: 20px;"><h4 style="font-size: 14px; font-weight: 700; color: var(--primary); border-bottom: 1px solid var(--glass-border); padding-bottom: 6px; margin-bottom: 10px;">📊 Self-Assessment History</h4>`;
                if (user.assessments && user.assessments.length > 0) {
                    html += `<div style="display: flex; flex-direction: column; gap: 8px; max-height: 200px; overflow-y: auto;">`;
                    user.assessments.forEach(a => {
                        const dateStr = new Date(a.completed_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
                        const typeLabel = a.assessment_type ? a.assessment_type.replace(/_/g, ' ').toUpperCase() : 'GENERAL';
                        html += `
                            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-weight: 600; font-size: 13px; color: var(--text-primary);">${typeLabel}</div>
                                    <div style="font-size: 11px; color: var(--text-muted);">${_escHtml(a.category || 'Log')} &bull; ${dateStr}</div>
                                </div>
                                <div style="font-size: 13px; font-weight: 700; color: var(--primary);">${a.score} pts</div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                } else {
                    html += `<p style="font-size: 12px; color: var(--text-muted);">No self-assessments completed yet.</p>`;
                }
                html += `</div>`;

                // Render Appointments & Progress Reports History
                html += `<div style="margin-top: 20px;"><h4 style="font-size: 14px; font-weight: 700; color: var(--primary); border-bottom: 1px solid var(--glass-border); padding-bottom: 6px; margin-bottom: 10px;">🗓️ Therapy Sessions &amp; Counselor Notes History</h4>`;
                if (user.appointments && user.appointments.length > 0) {
                    html += `<div style="display: flex; flex-direction: column; gap: 10px; max-height: 220px; overflow-y: auto;">`;
                    user.appointments.forEach(apt => {
                        let statusColor = 'var(--text-muted)';
                        if (apt.status === 'completed') statusColor = 'var(--success)';
                        else if (apt.status === 'accepted') statusColor = 'var(--primary)';
                        else if (apt.status === 'pending') statusColor = 'var(--warning)';

                        html += `
                            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 8px; padding: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 13px; color: var(--text-primary);">${_escHtml(apt.session_type || 'Session')} with ${_escHtml(apt.counselor_name || 'Counselor')}</div>
                                        <div style="font-size: 11px; color: var(--text-muted);">${_escHtml(apt.date)} at ${_escHtml(apt.time)}</div>
                                    </div>
                                    <span style="font-size: 10px; font-weight: 700; color: ${statusColor}; text-transform: uppercase;">${_escHtml(apt.status)}</span>
                                </div>
                                ${apt.counselor_progress_note ? `<div style="margin-top: 6px; padding: 8px; background: rgba(13,148,136,0.06); border-radius: 6px; font-size: 12px; color: var(--text-primary); line-height: 1.5;"><strong>Counselor Report:</strong> ${_escHtml(apt.counselor_progress_note)}</div>` : ''}
                            </div>
                        `;
                    });
                    html += `</div>`;
                } else {
                    html += `<p style="font-size: 12px; color: var(--text-muted);">No therapy sessions booked yet.</p>`;
                }
                html += `</div>`;

            } else if (role === 'counselor') {
                html += `<div><strong>Specialty:</strong> ${_escHtml(user.specialty || 'N/A')}</div>`;
                html += `<div><strong>Status:</strong> ${_escHtml(user.status || 'N/A')}</div>`;
                if (user.bio) {
                    html += `<div style="grid-column: 1 / -1; margin-top: 6px; font-style: italic;">&ldquo;${_escHtml(user.bio)}&rdquo;</div>`;
                }
                html += `</div></div>`;
            } else if (role === 'lecturer') {
                html += `<div><strong>Department:</strong> ${_escHtml(user.department || 'N/A')}</div>`;
                html += `<div><strong>University:</strong> ${_escHtml(user.university || 'N/A')}</div>`;
                html += `</div></div>`;
            }

            contentDiv.innerHTML = html;
            openModal('userDetailsModal');
        }
    } else {
        alert('Failed to load user details: ' + result.message);
    }
}

/**
 * Open Change Password modal for a user
 */
function openPasswordUpdate(userId, role) {
    document.getElementById('cpTargetUserId').value = userId;
    document.getElementById('cpTargetRole').value = role;
    document.getElementById('cpNewPassword').value = '';
    document.getElementById('cpConfirmPassword').value = '';
    document.getElementById('cpAdminPassword').value = '';
    openModal('changePasswordModal');
}

/**
 * Handle password update form submission
 */
async function handleUpdatePassword(event) {
    event.preventDefault();

    const userId       = document.getElementById('cpTargetUserId').value;
    const role         = document.getElementById('cpTargetRole').value;
    const newPassword  = document.getElementById('cpNewPassword').value;
    const confirmPass  = document.getElementById('cpConfirmPassword').value;
    const adminPass    = document.getElementById('cpAdminPassword').value;

    // Client-side validation
    if (newPassword.length < 6) {
        alert('New password must be at least 6 characters.');
        return;
    }
    if (newPassword !== confirmPass) {
        alert('Passwords do not match. Please re-enter.');
        document.getElementById('cpConfirmPassword').focus();
        return;
    }

    const submitBtn = document.getElementById('cpSubmitBtn');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerText = 'Updating...'; }

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'change_password',
        user_id: userId,
        role: role,
        new_password: newPassword,
        admin_password: adminPass
    });

    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = 'Update Password'; }

    if (result.success) {
        alert(result.message);
        closeModal('changePasswordModal');
    } else {
        alert('Password update failed: ' + result.message);
    }
}

/**
 * Open Therapy Sessions Audit Modal for Admin
 */
async function openAdminSessionsModal() {
    openModal('adminSessionsModal');
    const container = document.getElementById('adminSessionsListContent');
    if (!container) return;

    container.innerHTML = '<p style="color:var(--text-muted);">Loading therapy sessions...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=sessions');
    if (result.success && result.data.length > 0) {
        let html = `
            <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 12px; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--glass-border); color: var(--text-secondary);">
                        <th style="padding: 10px 8px;">Student</th>
                        <th style="padding: 10px 8px;">Counselor</th>
                        <th style="padding: 10px 8px;">Date & Time</th>
                        <th style="padding: 10px 8px;">Type</th>
                        <th style="padding: 10px 8px; text-align: right;">Status</th>
                    </tr>
                </thead>
                <tbody>
        `;
        result.data.forEach(s => {
            let statusColor = 'var(--text-muted)';
            if (s.status === 'accepted') statusColor = 'var(--primary)';
            else if (s.status === 'completed') statusColor = 'var(--success)';
            else if (s.status === 'pending') statusColor = 'var(--warning)';
            else if (s.status === 'rejected') statusColor = 'var(--danger)';

            html += `
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                    <td style="padding: 10px 8px; font-weight: 600; color: var(--text-primary);">${_escHtml(s.student_name)}<br><span style="font-size: 11px; color: var(--text-muted); font-weight: 400;">${_escHtml(s.student_department || '')}</span></td>
                    <td style="padding: 10px 8px; color: var(--text-secondary);">${_escHtml(s.counselor_name || 'Unassigned')}</td>
                    <td style="padding: 10px 8px; color: var(--text-secondary);">${_escHtml(s.date)} at ${_escHtml(s.time)}</td>
                    <td style="padding: 10px 8px; color: var(--text-secondary); text-transform: capitalize;">${_escHtml(s.session_type || 'Session')}</td>
                    <td style="padding: 10px 8px; text-align: right;"><span style="font-size: 11px; font-weight: 700; color: ${statusColor}; text-transform: uppercase;">${_escHtml(s.status)}</span></td>
                </tr>
            `;
        });
        html += `</tbody></table>`;
        container.innerHTML = html;
    } else {
        container.innerHTML = '<p style="color: var(--text-muted); padding: 16px 0;">No therapy sessions recorded yet.</p>';
    }
}

/**
 * Open Shared Stories Audit Modal for Admin
 */
async function openAdminStoriesModal() {
    openModal('adminStoriesModal');
    const container = document.getElementById('adminStoriesListContent');
    if (!container) return;

    container.innerHTML = '<p style="color:var(--text-muted);">Loading shared stories...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=stories');
    if (result.success && result.data.length > 0) {
        let html = '<div style="display:flex; flex-direction:column; gap:12px; margin-top:12px;">';
        result.data.forEach(st => {
            const author = st.is_anonymous == 1 ? 'Anonymous Student' : st.student_name;
            const dateStr = new Date(st.created_at).toLocaleDateString();
            html += `
                <div class="glass-card" style="padding: 16px; border-radius: 12px; background: rgba(255,255,255,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                        <div>
                            <h4 style="font-size: 15px; font-weight: 700; color: var(--primary); margin: 0 0 4px;">${_escHtml(st.title || 'Untitled Story')}</h4>
                            <span style="font-size: 12px; color: var(--text-muted);">By ${author} &bull; ${dateStr}</span>
                        </div>
                        <button class="btn-secondary" style="padding: 4px 10px; font-size: 11px; color: var(--danger); border-color: rgba(239, 68, 68, 0.3);" onclick="deleteAdminStory('${st.id}')">Delete Story</button>
                    </div>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.6; max-height: 120px; overflow-y: auto; white-space: pre-wrap;">${_escHtml(st.content)}</p>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    } else {
        container.innerHTML = '<p style="color: var(--text-muted); padding: 16px 0;">No shared stories submitted yet.</p>';
    }
}

/** Delete story helper for Admin modal */
async function deleteAdminStory(storyId) {
    if (!confirm('Are you sure you want to delete this shared story?')) return;
    const result = await apiRequest('admin/moderate_content.php', 'POST', {
        action: 'delete_story',
        item_id: parseInt(storyId)
    });
    if (result.success) {
        alert(result.message);
        openAdminStoriesModal();
        loadAdminOverviewStats();
    } else {
        alert('Deletion failed: ' + result.message);
    }
}

/**
 * Open Assessments Taken Audit Modal for Admin
 */
async function openAdminAssessmentsModal() {
    openModal('adminAssessmentsModal');
    const container = document.getElementById('adminAssessmentsListContent');
    if (!container) return;

    container.innerHTML = '<p style="color:var(--text-muted);">Loading assessment logs...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=assessments');
    if (result.success && result.data.length > 0) {
        let html = `
            <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 12px; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--glass-border); color: var(--text-secondary);">
                        <th style="padding: 10px 8px;">Student</th>
                        <th style="padding: 10px 8px;">Department</th>
                        <th style="padding: 10px 8px;">Assessment Type</th>
                        <th style="padding: 10px 8px;">Score</th>
                        <th style="padding: 10px 8px; text-align: right;">Completed At</th>
                    </tr>
                </thead>
                <tbody>
        `;
        result.data.forEach(a => {
            const dateStr = new Date(a.completed_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
            const typeLabel = a.assessment_type ? a.assessment_type.replace(/_/g, ' ').toUpperCase() : 'GENERAL';
            html += `
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                    <td style="padding: 10px 8px; font-weight: 600; color: var(--text-primary);">${_escHtml(a.student_name)}</td>
                    <td style="padding: 10px 8px; color: var(--text-secondary);">${_escHtml(a.student_department || 'N/A')}</td>
                    <td style="padding: 10px 8px; color: var(--primary); font-weight: 600;">${typeLabel}</td>
                    <td style="padding: 10px 8px; color: var(--text-primary); font-weight: 700;">${a.score} pts</td>
                    <td style="padding: 10px 8px; text-align: right; color: var(--text-muted); font-size: 12px;">${dateStr}</td>
                </tr>
            `;
        });
        html += `</tbody></table>`;
        container.innerHTML = html;
    } else {
        container.innerHTML = '<p style="color: var(--text-muted); padding: 16px 0;">No self-assessments completed yet.</p>';
    }
}

/**
 * Load Community Discussions for moderation tab
 */
async function loadStoriesForModeration() {
    const container = document.getElementById('adminDiscussionsList');
    if (!container) return;

    container.innerHTML = '<p style="color:var(--text-muted);">Loading community discussion posts...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=discussions');
    if (result.success && result.data.length > 0) {
        let html = '<div style="display:flex; flex-direction:column; gap:10px; margin-top:8px;">';
        result.data.forEach(d => {
            const timeStr = new Date(d.timestamp).toLocaleString();
            html += `
                <div class="glass-card" style="padding: 12px 16px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-size: 12px; font-weight: 700; color: var(--primary);">${_escHtml(d.sender_name)} <span style="font-weight: 400; color: var(--text-muted);">&bull; ${timeStr}</span></div>
                        <div style="font-size: 13px; color: var(--text-primary); margin-top: 4px;">${_escHtml(d.content)}</div>
                    </div>
                    <button class="btn-secondary" style="padding: 4px 10px; font-size: 11px; color: var(--danger); border-color: rgba(239, 68, 68, 0.3);" onclick="deleteAdminDiscussion('${d.id}')">Delete Post</button>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    } else {
        container.innerHTML = '<p style="color: var(--text-muted); padding: 12px 0;">No discussion posts found.</p>';
    }
}

/** Delete discussion post helper for Admin */
async function deleteAdminDiscussion(postId) {
    if (!confirm('Are you sure you want to delete this community post?')) return;
    const result = await apiRequest('admin/moderate_content.php', 'POST', {
        action: 'delete_discussion',
        item_id: parseInt(postId)
    });
    if (result.success) {
        alert(result.message);
        loadStoriesForModeration();
    } else {
        alert('Deletion failed: ' + result.message);
    }
}

/**
 * Submit secure deletion from modal form
 */
async function handleDeleteUser(event) {
    event.preventDefault();

    const userId        = document.getElementById('deleteTargetUserId').value;
    const currentRole   = document.getElementById('deleteTargetRole').value;
    const adminPassword = document.getElementById('adminPasswordInput').value;
    const reason        = document.getElementById('deleteReasonInput').value.trim();

    if (!reason) {
        alert('Please provide a reason for removing this user.');
        return;
    }

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Deleting...';
    submitBtn.disabled = true;

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action:         'delete_user',
        user_id:        parseInt(userId),
        admin_password: adminPassword,
        reason:         reason
    });

    submitBtn.innerText = originalText;
    submitBtn.disabled = false;

    if (result.success) {
        closeModal('deleteUserModal');
        alert(result.message);
        loadUsersList(currentRole);
    } else {
        alert('Deletion failed: ' + result.message);
    }
}

/**
 * Submit Counselor creation from modal form
 */
async function handleAddCounselor(event) {
    event.preventDefault();
    const form = event.target;
    
    const name = document.getElementById('counselorNameInput').value.trim();
    const email = document.getElementById('counselorEmailInput').value.trim();
    const password = document.getElementById('counselorPasswordInput').value;
    const specialty = document.getElementById('counselorSpecialtyInput').value.trim();
    const bio = document.getElementById('counselorBioInput').value.trim();

    if (!name || !email || !password || !specialty) {
        alert('Name, Email, Password, and Specialty are required.');
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Creating...';
    submitBtn.disabled = true;

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'add_counselor',
        name: name,
        email: email,
        password: password,
        specialty: specialty,
        bio: bio
    });

    submitBtn.innerText = originalText;
    submitBtn.disabled = false;

    if (result.success) {
        closeModal('addCounselorModal');
        alert(result.message);
        form.reset();
        // reload the user list if the active tab is counselor
        loadUsersList('counselor');
        // also reload stats overview
        loadAdminOverviewStats();
    } else {
        alert('Failed to add counselor: ' + result.message);
    }
}

/**
 * Submit Lecturer creation from modal form
 */
async function handleAddLecturer(event) {
    event.preventDefault();
    const form = event.target;
    
    const name = document.getElementById('lecturerNameInput').value.trim();
    const email = document.getElementById('lecturerEmailInput').value.trim();
    const password = document.getElementById('lecturerPasswordInput').value;
    const department = document.getElementById('lecturerDepartmentInput').value.trim();

    if (!name || !email || !password || !department) {
        alert('Name, Email, Password, and Department are required.');
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Creating...';
    submitBtn.disabled = true;

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'add_lecturer',
        name: name,
        email: email,
        password: password,
        department: department
    });

    submitBtn.innerText = originalText;
    submitBtn.disabled = false;

    if (result.success) {
        closeModal('addLecturerModal');
        alert(result.message);
        form.reset();
        loadUsersList('lecturer');
        loadAdminOverviewStats();
    } else {
        alert('Failed to add lecturer: ' + result.message);
    }
}

/**
 * Load universities listing for Admin
 */
async function loadUniversitiesList() {
    const container = document.getElementById('universitiesList');
    if (!container) return;

    container.innerHTML = '<p>Loading universities...</p>';

    const result = await apiRequest('admin/manage_users.php?action=get_universities');
    if (result.success && result.data.length > 0) {
        container.innerHTML = '';
        let listHtml = `
            <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 16px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--glass-border); color: var(--text-secondary); font-size: 14px;">
                        <th style="padding: 12px 8px;">University Name</th>
                        <th style="padding: 12px 8px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;
        result.data.forEach(uni => {
            listHtml += `
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);" id="uniRow_${uni.id}">
                    <td class="uni-name" style="padding: 12px 8px; font-weight: 500; color: var(--text-primary);">${uni.name}</td>
                    <td style="padding: 12px 8px; text-align: right;">
                        <button class="btn-secondary" style="color: var(--danger); border-color: var(--danger); font-size: 12px; padding: 6px 12px;" onclick="handleRemoveUniversity(${uni.id})">Remove</button>
                    </td>
                </tr>
            `;
        });
        listHtml += `</tbody></table>`;
        container.innerHTML = listHtml;
    } else if (result.success) {
        container.innerHTML = '<p style="color: var(--text-secondary); margin-top: 16px;">No universities registered yet.</p>';
    } else {
        container.innerHTML = '<p style="color: var(--danger); margin-top: 16px;">Failed to load universities: ' + result.message + '</p>';
    }
}

/**
 * Handle add university form submission
 */
async function handleAddUniversity(event) {
    event.preventDefault();
    const form = event.target;
    const nameInput = document.getElementById('universityNameInput');
    const name = nameInput ? nameInput.value.trim() : '';

    if (!name) {
        alert('University name is required.');
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Adding...';
    submitBtn.disabled = true;

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'add_university',
        name: name
    });

    submitBtn.innerText = originalText;
    submitBtn.disabled = false;

    if (result.success) {
        closeModal('addUniversityModal');
        alert(result.message);
        form.reset();
        loadUniversitiesList();
    } else {
        alert('Failed to add university: ' + result.message);
    }
}

/**
 * Handle removing a university from the registry
 */
async function handleRemoveUniversity(id) {
    const row = document.getElementById(`uniRow_${id}`);
    const nameEl = row ? row.querySelector('.uni-name') : null;
    const name = nameEl ? nameEl.innerText.trim() : 'this university';

    if (!confirm(`Are you sure you want to remove '${name}' from the registry?`)) {
        return;
    }

    const result = await apiRequest('admin/manage_users.php', 'POST', {
        action: 'delete_university',
        id: parseInt(id)
    });

    if (result.success) {
        alert(result.message);
        loadUniversitiesList();
    } else {
        alert('Failed to remove university: ' + result.message);
    }
}

/**
 * Load shared stories for moderation
 */
async function loadStoriesForModeration() {
    const list = document.getElementById('moderateStoriesList');
    if (!list) return;

    list.innerHTML = '<p>Loading stories...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=stories');
    if (result.success && result.data.length > 0) {
        list.innerHTML = '';
        result.data.forEach(story => {
            const date = new Date(story.created_at).toLocaleDateString();
            list.innerHTML += `
                <div class="glass-card" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: start; padding: 20px;">
                    <div style="flex: 1;">
                        <h4 style="font-size: 17px; font-weight: 700; color: var(--text-primary);">${story.title}</h4>
                        <span style="font-size: 12px; color: var(--text-muted);">Author: ${story.student_name} (${story.is_anonymous ? 'Anonymous toggle on' : 'Public name'}) | Posted: ${date}</span>
                        <p style="font-size: 14px; color: var(--text-secondary); margin-top: 10px;">${story.content}</p>
                    </div>
                    <button class="btn-secondary" style="color: var(--danger); border-color: var(--danger); font-size: 12px; padding: 6px 12px; margin-left: 20px;" onclick="deleteStory('${story.id}')">Remove Story</button>
                </div>
            `;
        });
    } else {
        list.innerHTML = '<p style="color: var(--text-secondary);">No shared stories on the board.</p>';
    }
}

/**
 * Action to delete a shared recovery story
 */
async function deleteStory(storyId) {
    if (confirm('Delete this story? It will be removed from public board.')) {
        const result = await apiRequest('admin/moderate_content.php', 'POST', {
            action: 'delete_story',
            item_id: storyId
        });

        if (result.success) {
            alert(result.message);
            loadStoriesForModeration();
        } else {
            alert('Failed to delete story: ' + result.message);
        }
    }
}

/**
 * Load group discussions list for moderation
 */
async function loadDiscussionsForModeration() {
    const list = document.getElementById('moderateStoriesList'); // reusing container space
    if (!list) return;

    list.innerHTML = '<p>Loading logs...</p>';

    const result = await apiRequest('admin/moderate_content.php?type=discussions');
    if (result.success && result.data.length > 0) {
        list.innerHTML = '';
        result.data.forEach(msg => {
            const date = new Date(msg.timestamp).toLocaleString();
            list.innerHTML += `
                <div class="glass-card" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; padding: 14px 20px;">
                    <div>
                        <span style="font-weight: 600; color: var(--primary);">${msg.sender_name}</span>
                        <span style="font-size: 11px; color: var(--text-muted); margin-left: 10px;">${date}</span>
                        <p style="font-size: 14px; color: var(--text-secondary); margin-top: 4px;">"${msg.content}"</p>
                    </div>
                    <button class="btn-secondary" style="color: var(--danger); border-color: var(--danger); font-size: 11px; padding: 4px 8px; margin-left: 20px;" onclick="deleteDiscussion('${msg.id}')">Delete Post</button>
                </div>
            `;
        });
    } else {
        list.innerHTML = '<p style="color: var(--text-secondary);">No discussions posted in community channel.</p>';
    }
}

/**
 * Action to delete a group discussion post
 */
async function deleteDiscussion(msgId) {
    if (confirm('Remove this message from group chat?')) {
        const result = await apiRequest('admin/moderate_content.php', 'POST', {
            action: 'delete_discussion',
            item_id: msgId
        });

        if (result.success) {
            alert(result.message);
            loadDiscussionsForModeration();
        } else {
            alert('Failed to remove message.');
        }
    }
}

/* ==========================================================================
   LIVE CHAT / SEMI-REAL-TIME MESSAGE ENGINE
   ========================================================================== */

/**
 * Setup and initialize the chat UI layout
 */
async function initChatPortal() {
    const listContainer = document.getElementById('chatUserList');
    if (!listContainer) return;

    listContainer.innerHTML = '<p style="padding: 16px;">Loading conversation channels...</p>';
    
    // Preserve pre-selected partner before reset
    const preSelectedPartnerId = App.chatPartnerId;
    const preSelectedPartnerName = App.chatPartnerName;

    // Stop direct chat timer
    stopChatPolling();
    App.chatPartnerId = null;
    App.lastChatTimestamp = null;
    
    // Render placeholder on main chat screen
    document.getElementById('chatActivePartnerName').innerText = 'Select a conversation';
    document.getElementById('chatMessagesBox').innerHTML = '<div style="margin: auto; text-align: center; color: var(--text-secondary);"><p>Select a contact to begin your counseling session</p></div>';
    document.getElementById('chatSendArea').style.display = 'none';

    // Fetch conversation partners.
    // Students can chat with counselors. Counselors can chat with students.
    // If student: get all counselors. If counselor: get all students who booked appointments.
    let endpoint = '';
    if (App.user.role === 'student') {
        endpoint = 'student/get_appointments.php?action=list_counselors';
    } else if (App.user.role === 'counselor') {
        endpoint = 'counselor/get_appointments.php'; // get list from appointments to know which students booked
    }

    const result = await apiRequest(endpoint);
    if (result.success) {
        listContainer.innerHTML = '';
        
        // De-duplicate contacts
        const contacts = [];
        const seenIds = new Set();

        if (App.user.role === 'student') {
            // Result contains {counselors: [...], group_recommended: ...}
            const counselors = result.data.counselors || [];
            counselors.forEach(c => {
                if (c.status === 'active' && !seenIds.has(c.user_id)) {
                    seenIds.add(c.user_id);
                    contacts.push({ id: c.user_id, name: c.name, sub: c.specialty });
                }
            });
        } else {
            // Counselor's booked appointments array
            result.data.forEach(apt => {
                const sId = apt.student_id;
                if (!seenIds.has(sId)) {
                    seenIds.add(sId);
                    contacts.push({ id: sId, name: apt.student_name, sub: 'Student Client' });
                }
            });
        }

        if (contacts.length === 0) {
            listContainer.innerHTML = '<p style="padding: 16px; color: var(--text-muted); font-size: 13px;">No contacts active yet.</p>';
            return;
        }

        contacts.forEach(c => {
            const isActiveClass = (App.chatPartnerId === c.id || preSelectedPartnerId === c.id) ? 'active' : '';
            listContainer.innerHTML += `
                <div class="chat-user-item ${isActiveClass}" id="partnerItem_${c.id}" onclick="selectChatPartner('${c.id}', '${c.name}')">
                    <div class="chat-user-avatar">${c.name.charAt(0)}</div>
                    <div class="chat-user-details">
                        <div class="chat-user-name">${c.name}</div>
                        <div class="chat-user-status">${c.sub}</div>
                    </div>
                </div>
            `;
        });

        // If a partner was pre-selected (routed from appointment link)
        if (preSelectedPartnerId) {
            selectChatPartner(preSelectedPartnerId, preSelectedPartnerName);
        }
    }
}

/**
 * Handle contact selection
 */
function selectChatPartner(partnerId, name) {
    App.chatPartnerId = partnerId;
    App.chatPartnerName = name;
    App.lastChatTimestamp = null; // reset timestamp to load full history
    
    // Highlight list item
    document.querySelectorAll('.chat-user-item').forEach(i => i.classList.remove('active'));
    const item = document.getElementById(`partnerItem_${partnerId}`);
    if (item) item.classList.add('active');

    // Show header and inputs
    document.getElementById('chatActivePartnerName').innerText = name;
    document.getElementById('chatSendArea').style.display = 'flex';
    document.getElementById('chatMessagesBox').innerHTML = '<p>Loading chat history...</p>';

    // Load full history, then start polling timer
    loadDirectMessages(partnerId, true);
    startChatPolling(partnerId);
}

/**
 * Poll messages from endpoint
 */
async function loadDirectMessages(partnerId, forceReset = false) {
    let url = `chat/get_messages.php?chat_type=direct&partner_id=${partnerId}`;
    if (!forceReset && App.lastChatTimestamp) {
        url += `&last_timestamp=${encodeURIComponent(App.lastChatTimestamp)}`;
    }

    const result = await apiRequest(url);
    const box = document.getElementById('chatMessagesBox');
    if (!box) return;

    if (result.success) {
        if (forceReset) box.innerHTML = '';

        if (result.data.length > 0) {
            // Remove loading placeholder if it's there
            if (box.innerHTML.includes('Loading chat history...')) {
                box.innerHTML = '';
            }

            result.data.forEach(msg => {
                const isOutgoing = msg.sender_id === App.user.id;
                const timeString = new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                
                box.innerHTML += `
                    <div class="chat-bubble-container ${isOutgoing ? 'outgoing' : 'incoming'}">
                        <span class="chat-bubble-sender">${isOutgoing ? 'You' : (msg.sender_name || 'Counselor')}</span>
                        <div class="chat-bubble">${msg.content}</div>
                        <span class="chat-bubble-time">${timeString}</span>
                    </div>
                `;
                
                // Track latest timestamp
                App.lastChatTimestamp = msg.timestamp;
            });

            // Scroll chat to bottom
            box.scrollTop = box.scrollHeight;
        } else if (forceReset) {
            box.innerHTML = '<div style="margin: auto; text-align: center; color: var(--text-muted);"><p>No messages exchanged yet. Send a greeting to start your conversation.</p></div>';
        }
    } else {
        box.innerHTML = `<div style="margin: auto; text-align: center; color: var(--danger); padding: 16px;"><p>Failed to load chat history: ${result.message || 'Internal server error'}</p></div>`;
    }
}

/**
 * Submit chat input box
 */
async function sendDirectMessage() {
    const input = document.getElementById('chatInputMessage');
    if (!input) return;

    const content = input.value.trim();
    if (!content) return;

    input.value = ''; // clear input immediately to optimize feel
    
    const result = await apiRequest('chat/send_message.php', 'POST', {
        chat_type: 'direct',
        receiver_id: App.chatPartnerId,
        content: content
    });

    if (result.success) {
        // Retrieve and append immediately
        loadDirectMessages(App.chatPartnerId);
    } else {
        alert('Failed to send message: ' + result.message);
    }
}

/**
 * Polling handlers
 */
function startChatPolling(partnerId) {
    stopChatPolling();
    App.chatPollInterval = setInterval(() => {
        loadDirectMessages(partnerId);
    }, 3000); // Poll every 3 seconds
}

function stopChatPolling() {
    if (App.chatPollInterval) {
        clearInterval(App.chatPollInterval);
        App.chatPollInterval = null;
    }
}


/* ==========================================================================
   GROUP DISCUSSION PORTAL
   ========================================================================== */

/**
 * Setup and initialize community discussion board
 */
function initGroupDiscussionPortal() {
    const box = document.getElementById('discussionMessagesBox');
    if (box) {
        box.innerHTML = '<p>Loading discussion threads...</p>';
    }

    App.lastGroupTimestamp = null;
    loadGroupMessages(true);
    
    // Start polling community posts
    stopGroupPolling();
    App.groupPollInterval = setInterval(() => {
        loadGroupMessages();
    }, 4000);
}

/**
 * Poll group posts
 */
async function loadGroupMessages(forceReset = false) {
    let url = 'chat/get_messages.php?chat_type=group';
    if (!forceReset && App.lastGroupTimestamp) {
        url += `&last_timestamp=${encodeURIComponent(App.lastGroupTimestamp)}`;
    }

    const result = await apiRequest(url);
    const box = document.getElementById('discussionMessagesBox');
    if (!box) return;

    if (result.success) {
        if (forceReset) box.innerHTML = '';

        if (result.data.length > 0) {
            if (box.innerHTML.includes('Loading discussion threads...')) {
                box.innerHTML = '';
            }

            result.data.forEach(msg => {
                const isMyMessage = msg.sender_id === App.user.id;
                const timeString = new Date(msg.timestamp).toLocaleString([], { hour: '2-digit', minute: '2-digit', month: 'short', day: 'numeric' });
                
                box.innerHTML += `
                    <div class="chat-bubble-container ${isMyMessage ? 'outgoing' : 'incoming'}" style="max-width: 80%; margin-bottom: 12px;">
                        <span class="chat-bubble-sender" style="color: var(--primary);">${msg.sender_name}</span>
                        <div class="chat-bubble">${msg.content}</div>
                        <span class="chat-bubble-time">${timeString}</span>
                    </div>
                `;
                App.lastGroupTimestamp = msg.timestamp;
            });
            box.scrollTop = box.scrollHeight;
        } else if (forceReset) {
            box.innerHTML = '<div style="margin: auto; text-align: center; color: var(--text-secondary);"><p>Welcome to the community discussions board! Share your thoughts anonymously or publicly here.</p></div>';
        }
    }
}

/**
 * Send group post
 */
async function sendGroupMessage() {
    const input = document.getElementById('discussionInputMessage');
    if (!input) return;

    const content = input.value.trim();
    if (!content) return;

    input.value = '';

    const result = await apiRequest('chat/send_message.php', 'POST', {
        chat_type: 'group',
        content: content
    });

    if (result.success) {
        loadGroupMessages();
    } else {
        alert('Failed to send community post: ' + result.message);
    }
}

function stopGroupPolling() {
    if (App.groupPollInterval) {
        clearInterval(App.groupPollInterval);
        App.groupPollInterval = null;
    }
}


/* ==========================================================================
   SYSTEM NOTIFICATIONS DROPDOWN
   ========================================================================== */

/**
 * Fetch and toggle alert indicator on Bell Icon
 */
async function loadNotifications() {
    const bellIcon = document.getElementById('notificationBellIcon');
    const badge = document.getElementById('notificationBadge');
    const dropdown = document.getElementById('notificationsDropdown');
    
    const result = await apiRequest('auth/notifications.php');
    if (result.success) {
        const unreadList = result.data.filter(n => !n.is_read);
        
        // Set Badge count
        if (badge) {
            if (unreadList.length > 0) {
                badge.innerText = unreadList.length;
                badge.style.display = 'flex';
                // Add animated glow pulse
                if (bellIcon) bellIcon.style.animation = 'pulse 1s infinite';
            } else {
                badge.style.display = 'none';
                if (bellIcon) bellIcon.style.animation = 'none';
            }
        }

        // Render drop-down details
        if (dropdown) {
            if (result.data.length > 0) {
                const unreadCount = unreadList.length;
                let headerHtml = `<div class="notifications-dropdown-header">
                    <span>Notifications</span>
                    ${unreadCount > 0 ? `<span style="font-size: 12px; font-weight: 600; color: var(--primary);">${unreadCount} new</span>` : ''}
                </div>`;
                
                let itemsHtml = '';
                result.data.forEach(n => {
                    const isUnreadClass = !n.is_read ? 'unread' : '';
                    const time = new Date(n.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                    itemsHtml += `
                        <div class="notification-item ${isUnreadClass}">
                            <div class="notif-message">${n.message}</div>
                            <span class="notif-time">${time}</span>
                        </div>
                    `;
                });

                dropdown.innerHTML = headerHtml + `<div class="notifications-dropdown-body">${itemsHtml}</div>`;
            } else {
                dropdown.innerHTML = `
                    <div class="notifications-dropdown-header"><span>Notifications</span></div>
                    <div style="padding: 32px 16px; text-align: center; color: var(--text-muted); font-size: 14px;">No notifications yet</div>
                `;
            }
        }
    }
}

/**
 * Toggle Notifications dropdown visibility and mark read
 */
async function toggleNotificationsDropdown() {
    const dropdown = document.getElementById('notificationsDropdown');
    if (!dropdown) return;

    dropdown.classList.toggle('active');
    
    // If opening, mark all notifications as read in backend
    if (dropdown.classList.contains('active')) {
        await apiRequest('auth/notifications.php?action=read', 'POST');
        // Instantly reset badge display client-side
        const badge = document.getElementById('notificationBadge');
        if (badge) badge.style.display = 'none';
        const bellIcon = document.getElementById('notificationBellIcon');
        if (bellIcon) bellIcon.style.animation = 'none';
    }
}


/* ==========================================================================
   UI UTILITY HELPERS (Modals, overlays)
   ========================================================================== */

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        if (modalId === 'registerModal') {
            loadRegisterUniversitiesDatalist();
        }
    } else if (modalId === 'loginModal') {
        const loginEmail = document.querySelector('input[name="email"]');
        if (loginEmail) {
            loginEmail.focus();
            loginEmail.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

/**
 * Load universities list publicly and populate registration datalist
 */
async function loadRegisterUniversitiesDatalist() {
    const datalist = document.getElementById('universitiesDatalist');
    if (!datalist) return;

    const result = await apiRequest('auth/register.php?action=get_universities');
    if (result.success && result.data.length > 0) {
        datalist.innerHTML = '';
        result.data.forEach(uni => {
            const option = document.createElement('option');
            option.value = uni.name;
            datalist.appendChild(option);
        });
    }
}

// Automatically load universities datalist on the landing page if the input exists
window.addEventListener('DOMContentLoaded', () => {
    const uniInput = document.getElementById('studentUniversityInput');
    if (uniInput) {
        loadRegisterUniversitiesDatalist();
    }
});

// Browser back button popstate listener for dashboard tabs
window.addEventListener('popstate', (event) => {
    if (event.state && event.state.tab) {
        switchTab(event.state.tab, true);
    } else {
        goBackStep();
    }
});

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

// Global modal overlay click close binding
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        if (e.target.id === 'videoConsultationModal') {
            closeVideoConsultationRoom();
        } else {
            e.target.classList.remove('active');
        }
    }
});

/**
 * Record student's choice of discussion group
 */
async function chooseDiscussionGroup(type) {
    const result = await apiRequest('student/choose_discussion.php', 'POST', { choice: type });
    if (result.success) {
        closeModal('discussionChoiceModal');
        alert(result.message);
        
        // Take actions based on choice
        if (type === 'chat') {
            switchTab('discussionTab');
        } else if (type === 'video') {
            openVideoConsultationRoom('https://meet.jit.si/mental_support_group_video_discussion');
        }
    } else {
        alert('Failed to save choice: ' + result.message);
    }
}

/**
 * Track that a session has started
 */
async function startSession(aptId) {
    try {
        await apiRequest('appointments/start_session.php', 'POST', { appointment_id: parseInt(aptId) });
    } catch (e) {
        console.error('Error starting session:', e);
    }
}

/* ============================================================
   Mental Health FAQ Chatbot Logic
   ============================================================ */

/**
 * Handle sending a chatbot message from input field
 */
async function submitChatbotMessage() {
    const input = document.getElementById('chatbotInputMessage');
    if (!input) return;
    
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    
    // Append student bubble
    appendChatbotMessage('You', text, 'outgoing');
    
    // Append typing loader
    const box = document.getElementById('chatbotMessagesBox');
    if (!box) return;
    
    const loaderId = 'chatbotTypingLoader';
    const loaderHtml = `
        <div class="chat-bubble-container incoming" id="${loaderId}">
            <div class="chat-bubble-sender">WellnessBot</div>
            <div class="chat-bubble" style="background: rgba(0, 0, 0, 0.05); color: var(--text-muted); font-style: italic; display: flex; align-items: center; gap: 8px;">
                <span class="animate-pulse">WellnessBot is typing...</span>
            </div>
        </div>
    `;
    box.insertAdjacentHTML('beforeend', loaderHtml);
    box.scrollTop = box.scrollHeight;
    
    // Call chatbot API
    const result = await apiRequest('student/chatbot.php', 'POST', { question: text });
    
    // Remove loader
    const loader = document.getElementById(loaderId);
    if (loader) loader.remove();
    
    if (result.success) {
        appendChatbotMessage('WellnessBot', result.answer, 'incoming', true);
    } else {
        appendChatbotMessage('WellnessBot', 'Sorry, I encountered an issue retrieving that answer. Please try again or ask a different question.', 'incoming');
    }
}

/**
 * Helper to click chatbot suggested question tags
 */
function sendChatbotQuestion(text) {
    const input = document.getElementById('chatbotInputMessage');
    if (input) {
        input.value = text;
        submitChatbotMessage();
    }
}

/**
 * Append message bubble to chatbot logs (WhatsApp Style)
 */
function appendChatbotMessage(sender, text, direction, isHtml = false) {
    const box = document.getElementById('chatbotMessagesBox');
    if (!box) return;
    
    const parsedText = isHtml ? parseChatbotMarkdown(text) : escapeHtmlTags(text);
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    let bubbleHtml = '';
    if (direction === 'outgoing') {
        // WhatsApp Outgoing Student Bubble (Light Green #dcf8c6, double checkmark)
        bubbleHtml = `
            <div class="chat-bubble-container outgoing" style="align-self: flex-end; max-width: 80%; margin-bottom: 6px;">
                <div class="whatsapp-bubble outgoing" style="
                    background: #dcf8c6;
                    color: #111b21;
                    padding: 10px 14px;
                    border-radius: 12px 12px 2px 12px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.15);
                    font-size: 14.5px;
                    line-height: 1.5;
                    position: relative;
                ">
                    <div>${parsedText}</div>
                    <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px; font-size: 10px; color: #667781; margin-top: 4px;">
                        <span>${timeStr}</span>
                        <span style="color: #53bdeb; font-weight: 700;">✓✓</span>
                    </div>
                </div>
            </div>
        `;
    } else {
        // WhatsApp Incoming WellnessBot Bubble (White #ffffff, sender title #128c7e)
        bubbleHtml = `
            <div class="chat-bubble-container incoming" style="align-self: flex-start; max-width: 82%; margin-bottom: 6px;">
                <div class="whatsapp-bubble incoming" style="
                    background: #ffffff;
                    color: #111b21;
                    padding: 10px 14px;
                    border-radius: 12px 12px 12px 2px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.15);
                    font-size: 14.5px;
                    line-height: 1.5;
                    position: relative;
                ">
                    <div style="font-weight: 700; font-size: 12px; color: #128c7e; margin-bottom: 4px;">${sender}</div>
                    <div>${parsedText}</div>
                    <div style="text-align: right; font-size: 10px; color: #667781; margin-top: 4px;">${timeStr}</div>
                </div>
            </div>
        `;
    }
    
    box.insertAdjacentHTML('beforeend', bubbleHtml);
    box.scrollTop = box.scrollHeight;
}

/**
 * Simple client-side markdown formatter for chatbot replies
 */
function parseChatbotMarkdown(markdown) {
    if (!markdown) return '';
    let html = markdown
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    
    // H3 titles ###
    html = html.replace(/^### (.*$)/gim, '<h3 style="font-size: 15px; font-weight: 700; margin-top: 10px; margin-bottom: 6px; color: var(--primary);">$1</h3>');
    // H2 titles ##
    html = html.replace(/^## (.*$)/gim, '<h2 style="font-size: 16px; font-weight: 700; margin-top: 12px; margin-bottom: 8px; color: var(--primary);">$1</h2>');
    // Bold text **
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Bullet list items
    html = html.replace(/^\* (.*$)/gim, '<li style="margin-left: 16px; margin-bottom: 4px; font-size: 13.5px;">$1</li>');
    html = html.replace(/^- (.*$)/gim, '<li style="margin-left: 16px; margin-bottom: 4px; font-size: 13.5px;">$1</li>');
    // Line breaks
    html = html.replace(/\n/g, '<br>');
    
    return html;
}

/**
 * Safe HTML tag escaper
 */
function escapeHtmlTags(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\n/g, '<br>');
}

/**
 * Chatbot Speech / Voice Recognition Logic
 */
let chatbotRecognition = null;
let isChatbotListening = false;

function toggleChatbotVoice() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        alert("Sorry, your browser does not support Speech Recognition. Please use Google Chrome, Microsoft Edge, or Safari.");
        return;
    }

    const micBtn = document.getElementById('chatbotMicBtn');
    const micIcon = document.getElementById('chatbotMicIcon');
    const statusBadge = document.getElementById('voiceStatusBadge');
    const input = document.getElementById('chatbotInputMessage');

    if (!chatbotRecognition) {
        chatbotRecognition = new SpeechRecognition();
        chatbotRecognition.continuous = false;
        chatbotRecognition.interimResults = false;
        chatbotRecognition.lang = 'en-US';

        chatbotRecognition.onstart = () => {
            isChatbotListening = true;
            if (micBtn) {
                micBtn.style.background = 'rgba(239, 68, 68, 0.15)';
                micBtn.style.borderColor = '#ef4444';
                micBtn.style.color = '#ef4444';
                micBtn.classList.add('pulse-mic');
            }
            if (statusBadge) {
                statusBadge.style.display = 'flex';
            }
            if (input) {
                input.placeholder = "Listening... Speak now...";
            }
        };

        chatbotRecognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            if (input) {
                input.value = transcript;
            }
        };

        chatbotRecognition.onerror = (event) => {
            console.error("Speech recognition error:", event.error);
            stopChatbotVoiceListening();
            if (event.error === 'not-allowed') {
                alert("Microphone permission denied. Please allow microphone access in your browser settings to speak to the chatbot.");
            } else if (event.error !== 'no-speech') {
                alert("Error in speech recognition: " + event.error);
            }
        };

        chatbotRecognition.onend = () => {
            stopChatbotVoiceListening();
            // Automatically submit message if we got text
            if (input && input.value.trim()) {
                submitChatbotMessage();
            }
        };
    }

    if (isChatbotListening) {
        chatbotRecognition.stop();
    } else {
        try {
            if (input) input.value = '';
            chatbotRecognition.start();
        } catch (e) {
            console.error("Error starting speech recognition:", e);
        }
    }
}

function stopChatbotVoiceListening() {
    isChatbotListening = false;
    const micBtn = document.getElementById('chatbotMicBtn');
    const statusBadge = document.getElementById('voiceStatusBadge');
    const input = document.getElementById('chatbotInputMessage');

    if (micBtn) {
        micBtn.style.background = 'rgba(255,255,255,0.04)';
        micBtn.style.borderColor = 'var(--glass-border)';
        micBtn.style.color = 'var(--text-secondary)';
        micBtn.classList.remove('pulse-mic');
    }
    if (statusBadge) {
        statusBadge.style.display = 'none';
    }
    if (input) {
        input.placeholder = "Ask me anything about mental health... or click 🎤 to speak";
    }
}

/**
 * Video Consultation Room — Custom WebRTC P2P System
 */
let vcLocalStream = null;
let vcPeerConnection = null;
let vcSignalInterval = null;
let vcRoomToken = null;
let vcMuted = false;
let vcCamOff = false;
let vcSplashTimer = null;
let vcIceQueue = [];

async function openVideoConsultationRoom(meetingUrl, aptId = null, otherPersonName = null) {
    if (aptId) {
        startSession(aptId);
    }

    // Determine roles
    const myRole = (App.user && App.user.role) ? App.user.role : 'student'; // 'counselor' or 'student'
    const isCaller = (myRole === 'counselor');

    // Build room name from URL or generate one
    let roomName = 'wellness_' + (aptId || Math.random().toString(36).substring(2, 10));
    if (meetingUrl && meetingUrl !== 'null' && meetingUrl !== 'undefined' && meetingUrl.trim() !== '') {
        const parts = meetingUrl.split('meet.jit.si/');
        if (parts.length > 1 && parts[1].trim()) {
            roomName = parts[1].trim();
        }
    }

    const displayName = otherPersonName
        || (myRole === 'student' ? 'Your Counselor' : 'Your Student');
    const initials = displayName.split(' ').filter(Boolean).map(w => w[0]).join('').toUpperCase().substring(0, 2) || '?';

    // Populate header, splash, and tile names/avatars
    ['vcName', 'vcSplashName', 'vcRemoteTileName'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = displayName;
    });
    ['vcAvatar', 'vcSplashAvatar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = initials;
    });
    const localTileName = document.getElementById('vcLocalTileName');
    if (localTileName) {
        localTileName.textContent = (App.user && App.user.name) ? App.user.name + ' (You)' : 'You (Self-View)';
    }
    
    const statusEl = document.getElementById('vcStatus');
    if (statusEl) statusEl.textContent = '🔒 Connecting…';

    const callingText = document.getElementById('vcCallingText');
    if (callingText) {
        callingText.textContent = isCaller ? 'Waiting for student to connect…' : 'Connecting to counselor…';
    }

    // Reset button states
    vcMuted = false;
    vcCamOff = false;
    const muteBtn = document.getElementById('vcMuteBtn');
    const camBtn  = document.getElementById('vcCamBtn');
    if (muteBtn) { 
        muteBtn.style.background = 'rgba(255,255,255,0.15)'; 
        muteBtn.innerHTML = `<i id="vcMuteIcon" data-lucide="mic" style="width:22px;height:22px;"></i>`;
    }
    if (camBtn) { 
        camBtn.style.background = 'rgba(255,255,255,0.15)'; 
        camBtn.innerHTML = `<i id="vcCamIcon" data-lucide="video" style="width:22px;height:22px;"></i>`;
    }
    if (typeof lucide !== 'undefined') {
        try { lucide.createIcons(); } catch (e) {}
    }

    // Show splash with ripple
    const splash = document.getElementById('vcCallingSplash');
    if (splash) { 
        splash.style.opacity = '1'; 
        splash.style.display = 'flex'; 
        splash.style.pointerEvents = 'none'; 
    }

    // Open the modal
    const modal = document.getElementById('videoConsultationModal');
    if (modal) {
        modal.style.display = 'flex';
    }

    // Clear old state/intervals/connections
    cleanupWebRTC();

    try {
        // Step 1: Create or fetch room token from PHP API
        const reqBody = aptId ? { appointment_id: aptId } : { room_name: roomName };
        const roomData = await apiRequest('video/room.php', 'POST', reqBody);
        if (!roomData || !roomData.success) {
            throw new Error((roomData && roomData.message) ? roomData.message : "Failed to create video room");
        }

        vcRoomToken = roomData.room_token;
        console.log("WebRTC Room token:", vcRoomToken, "| isCaller:", isCaller);

        // Step 2: Get camera/microphone FIRST so tracks are ready before PC is created
        await initLocalMedia();

        // Step 3: Initialize WebRTC PeerConnection and add local tracks
        initWebRTCConnection(isCaller);

        // Step 4: Start signaling poll (every 1.5 s)
        startSignalingPolling();

        // Step 5: If caller (counselor), explicitly create and send initial offer
        if (isCaller) {
            console.log("Caller initiating WebRTC offer...");
            await createWebRTCOffer();
        }

    } catch (err) {
        console.error("WebRTC room startup failed:", err);
        if (callingText) callingText.textContent = "Error setting up media: " + err.message;
        const statusEl = document.getElementById('vcStatus');
        if (statusEl) statusEl.textContent = '⚠️ Connection failed';
    }
}

function createSyntheticMediaStream() {
    const canvas = document.createElement('canvas');
    canvas.width = 640;
    canvas.height = 480;
    const ctx = canvas.getContext('2d');
    
    let step = 0;
    function draw() {
        step += 0.05;
        const grad = ctx.createLinearGradient(0, 0, 640, 480);
        grad.addColorStop(0, '#0f2027');
        grad.addColorStop(1, '#2c5364');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 640, 480);
        
        ctx.beginPath();
        ctx.arc(320, 200, 55 + Math.sin(step) * 4, 0, Math.PI * 2);
        ctx.fillStyle = '#0d9488';
        ctx.fill();
        
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 22px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(App.user ? App.user.name : 'User', 320, 290);
        ctx.font = '13px sans-serif';
        ctx.fillStyle = 'rgba(255,255,255,0.7)';
        ctx.fillText('Live WebRTC Stream Connected', 320, 320);
        
        requestAnimationFrame(draw);
    }
    draw();

    const stream = canvas.captureStream(30);
    
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const dst = audioCtx.createMediaStreamDestination();
        const gain = audioCtx.createGain();
        gain.gain.value = 0.0001;
        osc.connect(gain);
        gain.connect(dst);
        osc.start();
        dst.stream.getAudioTracks().forEach(t => stream.addTrack(t));
    } catch(e) {}
    
    return stream;
}

function initWebRTCConnection(isCaller) {
    const pcConfig = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' },
            { urls: 'stun:stun3.l.google.com:19302' },
            { urls: 'stun:stun4.l.google.com:19302' }
        ]
    };

    vcPeerConnection = new RTCPeerConnection(pcConfig);
    vcIceQueue = [];

    // Collect local ICE candidates and send to peer
    vcPeerConnection.onicecandidate = (event) => {
        if (event.candidate) {
            console.log('Sending ICE candidate');
            sendSignal('ice', event.candidate);
        }
    };

    vcPeerConnection.oniceconnectionstatechange = () => {
        console.log('ICE connection state:', vcPeerConnection.iceConnectionState);
    };

    // When remote stream track arrives, wire it to the remote <video> element
    vcPeerConnection.ontrack = (event) => {
        console.log('Remote track received, streams:', event.streams.length);
        const remoteVideo = document.getElementById('vcRemoteVideo');
        if (remoteVideo && event.streams && event.streams[0]) {
            remoteVideo.srcObject = event.streams[0];
            // Ensure autoplay in case browser blocked it
            remoteVideo.play().catch(e => console.warn('Remote video play():', e));
        }
        hideCallingSplash();
    };

    vcPeerConnection.onconnectionstatechange = () => {
        console.log('RTCPeerConnection state:', vcPeerConnection.connectionState);
        const statusEl = document.getElementById('vcStatus');
        if (statusEl) {
            if (vcPeerConnection.connectionState === 'connected') {
                statusEl.textContent = '🔒 Connected · End-to-end encrypted';
                hideCallingSplash();
            } else if (vcPeerConnection.connectionState === 'disconnected' ||
                       vcPeerConnection.connectionState === 'failed') {
                statusEl.textContent = '⚠️ Reconnecting...';
            }
        }
    };

    // onnegotiationneeded fires automatically once tracks are added.
    // Only the CALLER (counselor) should create the initial offer.
    if (isCaller) {
        vcPeerConnection.onnegotiationneeded = async () => {
            console.log('onnegotiationneeded fired — creating offer');
            await createWebRTCOffer();
        };
    }

    // Add local tracks (already captured in initLocalMedia) to the peer connection.
    // This triggers onnegotiationneeded on the caller side.
    if (vcLocalStream) {
        vcLocalStream.getTracks().forEach(track => {
            vcPeerConnection.addTrack(track, vcLocalStream);
        });
    }
}

async function initLocalMedia() {
    try {
        vcLocalStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    } catch (e1) {
        console.warn('Could not get camera+audio, attempting audio + synthetic video fallback.', e1);
        try {
            const audioStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
            const synStream = createSyntheticMediaStream();
            vcLocalStream = new MediaStream([
                ...synStream.getVideoTracks(),
                ...audioStream.getAudioTracks()
            ]);
        } catch (e2) {
            console.warn('Hardware media restricted — using synthetic video & audio fallback.', e2);
            vcLocalStream = createSyntheticMediaStream();
        }
    }

    // Show local preview
    const localVideo = document.getElementById('vcLocalVideo');
    if (localVideo) {
        localVideo.srcObject = vcLocalStream;
        localVideo.play().catch(e => console.warn('Local video play():', e));
    }
}

function startSignalingPolling() {
    if (vcSignalInterval) clearInterval(vcSignalInterval);

    vcSignalInterval = setInterval(async () => {
        if (!vcRoomToken) return;
        try {
            const data = await apiRequest(`video/signal.php?room_token=${encodeURIComponent(vcRoomToken)}`);
            if (data && data.success && data.signals && data.signals.length > 0) {
                for (const signal of data.signals) {
                    console.log('Received signal:', signal.signal_type);
                    if (signal.signal_type === 'offer') {
                        await handleReceivedOffer(signal.payload);
                    } else if (signal.signal_type === 'answer') {
                        await handleReceivedAnswer(signal.payload);
                    } else if (signal.signal_type === 'ice') {
                        handleReceivedIce(signal.payload);
                    }
                }
            }
        } catch (err) {
            console.error('Error in WebRTC signaling poll:', err);
        }
    }, 1500);
}

async function sendSignal(type, payload) {
    if (!vcRoomToken) return;
    try {
        await apiRequest('video/signal.php', 'POST', {
            room_token: vcRoomToken,
            signal_type: type,
            payload: payload
        });
    } catch (e) {
        console.error("Error sending WebRTC signal:", e);
    }
}

async function createWebRTCOffer() {
    if (!vcPeerConnection) return;
    try {
        console.log("Creating WebRTC offer...");
        const offer = await vcPeerConnection.createOffer();
        await vcPeerConnection.setLocalDescription(offer);
        await sendSignal('offer', offer);
    } catch (e) {
        console.error("Error creating WebRTC offer:", e);
    }
}

async function handleReceivedOffer(offer) {
    if (!vcPeerConnection || !offer) return;
    // Only accept an offer when we are in 'stable' state (callee / student side)
    if (vcPeerConnection.signalingState !== 'stable') {
        console.warn('Ignoring offer — signalingState is', vcPeerConnection.signalingState);
        return;
    }
    try {
        console.log('Received offer, setting remote description...');
        await vcPeerConnection.setRemoteDescription(new RTCSessionDescription(offer));

        console.log('Creating answer...');
        const answer = await vcPeerConnection.createAnswer();
        await vcPeerConnection.setLocalDescription(answer);
        await sendSignal('answer', answer);

        processQueuedIceCandidates();
        setTimeout(() => hideCallingSplash(), 1200);
    } catch (e) {
        console.error('Error handling received offer:', e);
    }
}

async function handleReceivedAnswer(answer) {
    if (!vcPeerConnection || !answer) return;
    if (vcPeerConnection.signalingState !== 'have-local-offer') return;
    try {
        console.log("Received WebRTC answer, setting remote description...");
        await vcPeerConnection.setRemoteDescription(new RTCSessionDescription(answer));

        processQueuedIceCandidates();
        setTimeout(() => hideCallingSplash(), 1200);
    } catch (e) {
        console.error("Error handling WebRTC answer:", e);
    }
}

function handleReceivedIce(candidate) {
    if (!candidate || !candidate.candidate) return;
    if (vcPeerConnection && vcPeerConnection.remoteDescription && vcPeerConnection.remoteDescription.type) {
        console.log("Adding ICE candidate directly...");
        vcPeerConnection.addIceCandidate(new RTCIceCandidate(candidate)).catch(e => {
            console.warn("Skipped candidate:", e);
        });
    } else {
        console.log("Queuing ICE candidate...");
        vcIceQueue.push(candidate);
    }
}

function processQueuedIceCandidates() {
    if (!vcPeerConnection) return;
    console.log("Processing queued ICE candidates:", vcIceQueue.length);
    while (vcIceQueue.length > 0) {
        const candidate = vcIceQueue.shift();
        if (candidate && candidate.candidate) {
            vcPeerConnection.addIceCandidate(new RTCIceCandidate(candidate)).catch(e => {
                console.warn("Skipped candidate:", e);
            });
        }
    }
}

function hideCallingSplash() {
    const sp = document.getElementById('vcCallingSplash');
    if (sp && sp.style.display !== 'none') {
        sp.style.transition = 'opacity 0.7s ease';
        sp.style.opacity = '0';
        setTimeout(() => {
            if (sp) sp.style.display = 'none';
        }, 700);
    }
}

function cleanupWebRTC() {
    if (vcSignalInterval) {
        clearInterval(vcSignalInterval);
        vcSignalInterval = null;
    }
    if (vcSplashTimer) {
        clearTimeout(vcSplashTimer);
        vcSplashTimer = null;
    }
    if (vcLocalStream) {
        vcLocalStream.getTracks().forEach(track => track.stop());
        vcLocalStream = null;
    }
    if (vcPeerConnection) {
        vcPeerConnection.close();
        vcPeerConnection = null;
    }
    vcIceQueue = [];
    vcRoomToken = null;
}

async function closeVideoConsultationRoom() {
    // Notify signaling that we are hanging up (deletes room and signals)
    if (vcRoomToken) {
        try {
            await apiRequest(`video/room.php?room_token=${encodeURIComponent(vcRoomToken)}`, 'DELETE');
        } catch (e) {
            console.error("Error deleting room:", e);
        }
    }

    cleanupWebRTC();

    // Reset video DOM elements
    const remoteVideo = document.getElementById('vcRemoteVideo');
    const localVideo = document.getElementById('vcLocalVideo');
    if (remoteVideo) remoteVideo.srcObject = null;
    if (localVideo) localVideo.srcObject = null;

    // Reset splash display
    const splash = document.getElementById('vcCallingSplash');
    if (splash) {
        splash.style.display = 'flex';
        splash.style.opacity = '1';
    }

    // Close modal
    const modal = document.getElementById('videoConsultationModal');
    if (modal) modal.style.display = 'none';

    // Refresh appointment lists
    if (App.user && App.user.role === 'student') {
        loadStudentAppointments();
    } else if (App.user && App.user.role === 'counselor') {
        loadCounselorAppointments();
    }
}

function vcToggleMute() {
    vcMuted = !vcMuted;
    const btn = document.getElementById('vcMuteBtn');
    if (btn) {
        btn.style.background = vcMuted ? 'rgba(229,57,53,0.6)' : 'rgba(255,255,255,0.15)';
        btn.innerHTML = `<i id="vcMuteIcon" data-lucide="${vcMuted ? 'mic-off' : 'mic'}" style="width:22px;height:22px;"></i>`;
        if (typeof lucide !== 'undefined') {
            try { lucide.createIcons(); } catch (e) {}
        }
    }

    if (vcLocalStream) {
        vcLocalStream.getAudioTracks().forEach(track => {
            track.enabled = !vcMuted;
        });
    }
}

function vcToggleCam() {
    vcCamOff = !vcCamOff;
    const btn = document.getElementById('vcCamBtn');
    if (btn) {
        btn.style.background = vcCamOff ? 'rgba(229,57,53,0.6)' : 'rgba(255,255,255,0.15)';
        btn.innerHTML = `<i id="vcCamIcon" data-lucide="${vcCamOff ? 'video-off' : 'video'}" style="width:22px;height:22px;"></i>`;
        if (typeof lucide !== 'undefined') {
            try { lucide.createIcons(); } catch (e) {}
        }
    }

    if (vcLocalStream) {
        vcLocalStream.getVideoTracks().forEach(track => {
            track.enabled = !vcCamOff;
        });
    }
}


/* ==========================================================================
   LECTURER PORTAL LOGIC
   ========================================================================== */

let lecturerStudentsData = []; // Cache list of students for clientside filtering

/**
 * Initialize the Lecturer Dashboard
 */
function initLecturerDashboard() {
    // Read user from localStorage
    const savedUser = localStorage.getItem('mhp_user');
    if (!savedUser) {
        window.location.href = 'index.html?error=unauthorized';
        return;
    }
    App.user = JSON.parse(savedUser);
    
    if (App.user.role !== 'lecturer') {
        window.location.href = 'index.html?error=unauthorized';
        return;
    }

    // Set greeting name and avatar
    const greetingEl = document.getElementById('userGreeting');
    if (greetingEl) greetingEl.innerText = App.user.name;

    const universityEl = document.getElementById('userUniversity');
    if (universityEl && App.user.university) {
        universityEl.innerText = App.user.university;
    }

    const deptEl = document.getElementById('lecturerDept');
    if (deptEl && App.user.department) {
        deptEl.innerText = App.user.department + " Department";
    }

    const avatarPlaceholder = document.getElementById('avatarLetter');
    if (avatarPlaceholder) {
        if (App.user.profile_picture) {
            avatarPlaceholder.innerHTML = `<img src="${App.user.profile_picture}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
        } else {
            avatarPlaceholder.innerText = App.user.name.charAt(0).toUpperCase();
        }
    }

    // Sidebar tab routing setup
    const sidebarLinks = document.querySelectorAll('.sidebar-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', () => {
            const targetTab = link.getAttribute('data-tab');
            switchLecturerTab(targetTab);
        });
    });

    // Load initial student list
    loadLecturerStudentsList();
}

/**
 * Switch Active Tab in Lecturer Dashboard
 */
function switchLecturerTab(tabId) {
    document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    const link = document.querySelector(`.sidebar-link[data-tab="${tabId}"]`);
    const content = document.getElementById(tabId);
    
    if (link) link.classList.add('active');
    if (content) content.classList.add('active');

    if (tabId === 'studentsProgressTab') {
        loadLecturerStudentsList();
    }
}

/**
 * Load list of students at the lecturer's university
 */
async function loadLecturerStudentsList() {
    const container = document.getElementById('lecturerStudentsList');
    if (!container) return;

    container.innerHTML = '<p>Loading students list...</p>';

    const result = await apiRequest('lecturer/get_students.php');
    if (result.success) {
        lecturerStudentsData = result.data || [];
        renderLecturerStudents(lecturerStudentsData);
    } else {
        container.innerHTML = '<p style="color: var(--danger);">Failed to load students: ' + result.message + '</p>';
    }
}

/**
 * Render students list
 */
function renderLecturerStudents(students) {
    const container = document.getElementById('lecturerStudentsList');
    if (!container) return;

    if (students.length === 0) {
        container.innerHTML = '<p style="color: var(--text-secondary);">No student progress records found matching criteria.</p>';
        return;
    }

    let listHtml = `
        <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 16px;">
            <thead>
                <tr style="border-bottom: 1px solid var(--glass-border); color: var(--text-secondary); font-size: 14px;">
                    <th style="padding: 12px 8px;">Name</th>
                    <th style="padding: 12px 8px;">Student ID</th>
                    <th style="padding: 12px 8px;">Department</th>
                    <th style="padding: 12px 8px;">Email</th>
                    <th style="padding: 12px 8px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
    `;

    students.forEach(student => {
        listHtml += `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                <td style="padding: 12px 8px; font-weight: 500; color: var(--text-primary);">${student.name}</td>
                <td style="padding: 12px 8px; color: var(--text-secondary);">${student.student_id || 'N/A'}</td>
                <td style="padding: 12px 8px; color: var(--text-secondary);">${student.department || 'N/A'}</td>
                <td style="padding: 12px 8px; color: var(--text-secondary);">${student.email || 'N/A'}</td>
                <td style="padding: 12px 8px; text-align: right;">
                    <button class="btn-primary" style="padding: 5px 12px; font-size: 12px; width: auto;" onclick="openLecturerStudentDetailsModal('${student.user_id}')">View Profile</button>
                </td>
            </tr>
        `;
    });

    listHtml += `</tbody></table>`;
    container.innerHTML = listHtml;
}

/**
 * Client-side search and wellness level filter
 */
function filterLecturerStudents() {
    const searchVal = document.getElementById('studentProgressSearch').value.toLowerCase().trim();

    const filtered = lecturerStudentsData.filter(student => {
        return student.name.toLowerCase().includes(searchVal) || 
               (student.student_id && student.student_id.toLowerCase().includes(searchVal)) ||
               (student.department && student.department.toLowerCase().includes(searchVal));
    });

    renderLecturerStudents(filtered);
}

/**
 * Open student wellness details modal for lecturers
 */
async function openLecturerStudentDetailsModal(studentId) {
    const container = document.getElementById('studentAuditDetails');
    if (!container) return;

    container.innerHTML = '<p>Loading student information...</p>';
    openModal('studentAuditModal');

    const result = await apiRequest(`lecturer/student_details.php?student_id=${studentId}`);
    if (result.success) {
        const profile = result.data.profile;

        // Fetch counselor progress notes for student (if same department)
        const reportRes = await apiRequest(`lecturer/progress_reports.php?student_id=${studentId}`);
        let reportsHtml = '';
        if (reportRes.success && reportRes.data && reportRes.data.length > 0) {
            reportsHtml = `
                <div style="margin-top: 20px;">
                    <h4 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 12px; font-weight: 700; color: var(--primary);">📋 Counselor Progress Reports</h4>
                    <div style="display: flex; flex-direction: column; gap: 10px; max-height: 250px; overflow-y: auto;">
            `;
            reportRes.data.forEach(r => {
                reportsHtml += `
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 12px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); margin-bottom: 6px;">
                            <span>Counselor: <strong style="color: var(--text-primary);">${_escHtml(r.counselor_name)}</strong> (${_escHtml(r.counselor_specialty || 'Licensed Counselor')})</span>
                            <span>${new Date(r.date).toLocaleDateString()}</span>
                        </div>
                        <div style="font-size: 13px; color: var(--text-primary); line-height: 1.5; background: rgba(13,148,136,0.06); padding: 10px; border-radius: 8px;">
                            ${_escHtml(r.counselor_progress_note)}
                        </div>
                    </div>
                `;
            });
            reportsHtml += `</div></div>`;
        } else if (reportRes.success) {
            reportsHtml = `<div style="margin-top: 20px; padding: 14px; background: rgba(255,255,255,0.03); border-radius: 8px; text-align: center; color: var(--text-muted); font-size: 13px;">No counselor progress notes filed for this student yet.</div>`;
        } else if (reportRes.message) {
            reportsHtml = `<div style="margin-top: 20px; padding: 14px; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); border-radius: 8px; color: #f87171; font-weight: 700; font-size: 13px; text-align: center;">⚠️ ${_escHtml(reportRes.message)}</div>`;
        }

        container.innerHTML = `
            <div style="padding: 16px; background: rgba(var(--primary-rgb, 99,102,241), 0.08); border-radius: 12px; border-left: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: #fff; flex-shrink: 0;">
                        ${profile.name ? profile.name.charAt(0).toUpperCase() : 'S'}
                    </div>
                    <div>
                        <h3 style="color: var(--primary); font-size: 20px; margin: 0 0 2px;">${_escHtml(profile.name)}</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">Student Profile</span>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; color: var(--text-secondary);">
                    <div><strong style="color: var(--text-primary);">Department:</strong> ${_escHtml(profile.department || 'N/A')}</div>
                    <div><strong style="color: var(--text-primary);">Student ID:</strong> ${_escHtml(profile.card_num || 'N/A')}</div>
                    <div><strong style="color: var(--text-primary);">Email:</strong> ${_escHtml(profile.email || 'N/A')}</div>
                    <div><strong style="color: var(--text-primary);">Phone:</strong> ${_escHtml(profile.phone || 'N/A')}</div>
                    <div><strong style="color: var(--text-primary);">University:</strong> ${_escHtml(profile.university || 'N/A')}</div>
                </div>
                ${profile.bio ? `<div style="margin-top: 12px; padding: 10px; background: rgba(255,255,255,0.04); border-radius: 8px; font-style: italic; font-size: 13px; color: var(--text-secondary);">&ldquo;${_escHtml(profile.bio)}&rdquo;</div>` : ''}
            </div>
            ${reportsHtml}
        `;
    } else {
        const errMsg = result.message || 'not allowed to view report for this student';
        container.innerHTML = `
            <div style="padding: 24px; text-align: center; background: rgba(239,68,68,0.1); border: 2px solid #ef4444; border-radius: 14px; margin: 10px 0;">
                <div style="font-size: 32px; margin-bottom: 8px;">🚫</div>
                <h4 style="color: #ef4444; font-size: 16px; font-weight: 700; margin-bottom: 6px;">Access Restricted</h4>
                <p style="color: #f87171; font-size: 14px; font-weight: 600; margin: 0;">${_escHtml(errMsg)}</p>
            </div>
        `;
    }
}

/**
 * Handle password changes inside lecturer settings
 */
async function handleSettingsPasswordUpdate(event) {
    event.preventDefault();
    const form = event.target;
    const currentPassword = form.current_password.value;
    const newPassword = form.new_password.value;
    const confirmNewPassword = form.confirm_new_password.value;

    if (newPassword.length < 6) {
        alert('Password must be at least 6 characters.');
        return;
    }
    if (newPassword !== confirmNewPassword) {
        alert('New passwords do not match.');
        return;
    }

    const result = await apiRequest('auth/update_password.php', 'POST', {
        current_password: currentPassword,
        new_password: newPassword
    });

    if (result.success) {
        alert(result.message);
        form.reset();
    } else {
        alert('Password update failed: ' + result.message);
    }
}

// ============================================================
// COMMUNITY GROUP VIDEO DISCUSSION ROOM
// ============================================================

const GV = {
    stream: null,
    isMuted: false,
    isCamOff: false,
    roomId: null,
    roomName: 'community_group_discussion',
    heartbeatTimer: null,
    statusTimer: null,
    signalPollTimer: null,
    isModerator: false,
    myUserId: null,
    peers: {},           // { user_id: RTCPeerConnection }
    remoteStreams: {},   // { user_id: MediaStream }
    knownPeers: new Set() // user_ids we've opened connections for
};

const GV_ICE_CONFIG = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' }
    ]
};

/**
 * Opens the Community Group Video Discussion Room.
 */
async function openGroupVideoDiscussionRoom() {
    const user = App.user;
    if (!user) { alert('Please log in first.'); return; }

    const modal = document.getElementById('groupVideoDiscussionModal');
    if (!modal) { alert('Group video room UI not found.'); return; }

    modal.style.display = 'flex';

    const role = (user.role || '').toLowerCase();
    const isPractitioner = (role === 'counselor' || role === 'admin');
    GV.isModerator = isPractitioner;

    const modBar = document.getElementById('gvModeratorBar');
    if (modBar) modBar.style.display = isPractitioner ? 'flex' : 'none';

    // Request camera & mic — use synthetic fallback if hardware unavailable
    try {
        GV.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    } catch (e1) {
        console.warn('Could not get camera+audio, trying audio + synthetic video:', e1);
        try {
            const audioStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
            const synStream = createSyntheticMediaStream();
            GV.stream = new MediaStream([
                ...synStream.getVideoTracks(),
                ...audioStream.getAudioTracks()
            ]);
        } catch (e2) {
            console.warn('Full media fallback — synthetic only:', e2);
            GV.stream = createSyntheticMediaStream();
        }
    }

    // Store my user id for peer connection logic
    GV.myUserId = parseInt(user.id || user.user_id || 0, 10);
    GV.peers = {};
    GV.remoteStreams = {};
    GV.knownPeers = new Set();

    // Join room via API (uses apiRequest so ngrok header is included)
    try {
        const data = await apiRequest('video/group_room.php', 'POST', { action: 'join' });
        if (data && data.success) {
            GV.roomId = data.room_id || 'main';
        }
    } catch (e) {
        console.warn('Group room join error:', e);
    }

    GV.isMuted = false;
    GV.isCamOff = false;
    _gvUpdateMuteUI();
    _gvUpdateCamUI();

    // Wire local stream into self-view video tile
    const localVid = document.getElementById('gvLocalVideo');
    const myNameEl = document.getElementById('gvMyVideoName');
    const myAvatarEl = document.getElementById('gvMyVideoAvatar');
    if (localVid && GV.stream) {
        localVid.srcObject = GV.stream;
        localVid.play().catch(e => console.warn('gvLocalVideo play():', e));
        const camOffEl = document.getElementById('gvMyVideoCamOff');
        if (camOffEl) camOffEl.style.display = 'none';
    } else if (localVid) {
        const camOffEl = document.getElementById('gvMyVideoCamOff');
        if (camOffEl) camOffEl.style.display = 'flex';
    }
    if (myNameEl && user.name) myNameEl.textContent = isPractitioner ? user.name + ' (Moderator)' : user.name;
    if (myAvatarEl && user.name) myAvatarEl.textContent = user.name.charAt(0).toUpperCase();

    _gvStartHeartbeat();
    _gvStartStatusPoll();
    _gvStartSignalPoll();
    _gvFetchAndRenderStatus();

    if (window.lucide) lucide.createIcons();
}

function _gvStartHeartbeat() {
    _gvStopHeartbeat();
    GV.heartbeatTimer = setInterval(async () => {
        try {
            await apiRequest('video/group_room.php', 'POST', { action: 'heartbeat' });
        } catch (_) {}
    }, 5000);
}
function _gvStopHeartbeat() {
    if (GV.heartbeatTimer) { clearInterval(GV.heartbeatTimer); GV.heartbeatTimer = null; }
}

function _gvStartStatusPoll() {
    _gvStopStatusPoll();
    GV.statusTimer = setInterval(_gvFetchAndRenderStatus, 4000);
}
function _gvStopStatusPoll() {
    if (GV.statusTimer) { clearInterval(GV.statusTimer); GV.statusTimer = null; }
}

async function _gvFetchAndRenderStatus() {
    try {
        const data = await apiRequest('video/group_room.php?action=status');
        if (data && data.success) {
            renderGroupVideoGrid(data);

            const roomObj = data.room || {};
            if (roomObj.is_active == 0 || data.room_ended) {
                alert('The practitioner moderator has ended this discussion session.');
                leaveGroupVideoRoom();
                return;
            }

            // Check if current user was muted by moderator
            const participants = data.participants || [];
            if (App.user && App.user.role === 'student') {
                const me = participants.find(p => p.user_id == App.user.id);
                if (me && me.audio_muted == 1 && !GV.isMuted) {
                    GV.isMuted = true;
                    if (GV.stream) GV.stream.getAudioTracks().forEach(t => { t.enabled = false; });
                    _gvUpdateMuteUI();
                }
            }
        }
    } catch (e) {
        console.warn('GV status poll error:', e);
    }
}

function renderGroupVideoGrid(data) {
    const participants = data.participants || [];
    const roomObj = data.room || {};
    const topic = roomObj.topic || data.topic || 'General Mental Health Support & Discussion';

    const topicEl = document.getElementById('gvTopicText');
    if (topicEl) topicEl.textContent = 'Topic: ' + topic;

    const countEl = document.getElementById('gvMemberCount');
    if (countEl) countEl.textContent = participants.length;

    const mod = participants.find(function(p) {
        return p.is_moderator == 1 || p.role === 'counselor' || p.role === 'admin';
    });
    if (mod) {
        const modNameEl = document.getElementById('gvModeratorName');
        const spotNameEl = document.getElementById('gvSpotlightName');
        const spotAvEl   = document.getElementById('gvSpotlightAvatar');
        if (modNameEl) modNameEl.textContent = 'Moderator: ' + mod.user_name;
        if (spotNameEl) spotNameEl.textContent = mod.user_name;
        if (spotAvEl)   spotAvEl.textContent = mod.user_name ? mod.user_name.charAt(0).toUpperCase() : 'P';
    }

    // Participants other than myself — soft-update tiles (don't replace existing ones)
    const others = participants.filter(function(p) {
        return parseInt(p.user_id, 10) !== GV.myUserId;
    });

    const grid = document.getElementById('gvVideoGrid');
    if (!grid) return;

    if (others.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:rgba(255,255,255,0.4);padding:40px 0;font-size:14px;">No other participants yet. Invite others to join this discussion.</div>';
    } else {
        // Add tiles for new participants
        others.forEach(function(p) {
            const pid = parseInt(p.user_id, 10);
            const tileId = 'gvtile_' + pid;
            if (!document.getElementById(tileId)) {
                // Tile does not exist yet — create it
                const div = document.createElement('div');
                div.id = tileId;
                div.innerHTML = _gvParticipantTileHTML(p);
                grid.appendChild(div.firstElementChild || div);
            }
            // Re-attach remote stream if we already have it
            const vid = document.getElementById('gv_vid_' + pid);
            const remoteStream = GV.remoteStreams[pid];
            if (vid && remoteStream && vid.srcObject !== remoteStream) {
                vid.srcObject = remoteStream;
                vid.play().catch(function() {});
                const av = document.getElementById('gv_vid_' + pid + '_av');
                if (av) av.style.display = 'none';
            }
        });

        // Remove tiles for participants who left
        const currentIds = new Set(others.map(function(p) { return 'gvtile_' + parseInt(p.user_id, 10); }));
        Array.from(grid.children).forEach(function(child) {
            if (child.id && child.id.startsWith('gvtile_') && !currentIds.has(child.id)) {
                grid.removeChild(child);
            }
        });
    }

    // Update member sidebar list
    const listEl = document.getElementById('gvMemberList');
    if (listEl) {
        listEl.innerHTML = participants.map(function(p) {
            var initial = p.user_name ? p.user_name.charAt(0).toUpperCase() : '?';
            var isMod = p.is_moderator == 1 || p.role === 'counselor' || p.role === 'admin';
            var muted = p.audio_muted == 1;
            return '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,0.04);">' +
                '<div style="width:32px;height:32px;border-radius:50%;background:' + (isMod ? 'linear-gradient(135deg,#0d9488,#6366f1)' : 'rgba(255,255,255,0.15)') + ';display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;flex-shrink:0;">' + initial + '</div>' +
                '<div style="min-width:0;flex:1;">' +
                    '<div style="font-size:12.5px;color:#fff;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + _escHtml(p.user_name) + '</div>' +
                    '<div style="font-size:11px;color:rgba(255,255,255,0.5);">' + (isMod ? 'Practitioner' : 'Participant') + (muted ? ' · muted' : '') + '</div>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    // Trigger WebRTC connections for any new participants
    _gvHandleParticipantChanges(participants);
}

// Returns the inner HTML for a participant tile wrapper element (used on first create only)
function _gvParticipantTileHTML(p) {
    var pid = parseInt(p.user_id, 10);
    var vidId = 'gv_vid_' + pid;
    var avId  = 'gv_vid_' + pid + '_av';
    var initial = p.user_name ? p.user_name.charAt(0).toUpperCase() : '?';
    var muted = p.audio_muted == 1;
    var isMod = p.is_moderator == 1 || p.role === 'counselor' || p.role === 'admin';
    return '<div id="gvtile_' + pid + '" style="background:#161b22;border:1px solid rgba(255,255,255,0.12);border-radius:16px;overflow:hidden;position:relative;">' +
        '<div style="aspect-ratio:16/9;background:#0d1117;position:relative;">' +
            '<video id="' + vidId + '" autoplay playsinline style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;z-index:2;background:#000;"></video>' +
            '<div style="position:absolute;inset:0;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,rgba(99,102,241,0.2),rgba(139,92,246,0.2));" id="' + avId + '">' +
                '<div style="width:54px;height:54px;border-radius:50%;background:' + (isMod ? 'linear-gradient(135deg,#0d9488,#6366f1)' : 'linear-gradient(135deg,#6366f1,#8b5cf6)') + ';display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,0.3);">' + initial + '</div>' +
                '<span style="color:rgba(255,255,255,0.7);font-size:11px;font-weight:600;">' + _escHtml(p.user_name) + '</span>' +
                '<span style="color:rgba(255,255,255,0.4);font-size:10px;">Connecting...</span>' +
            '</div>' +
        '</div>' +
        '<div style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between;background:rgba(0,0,0,0.4);border-top:1px solid rgba(255,255,255,0.06);">' +
            '<div style="display:flex;align-items:center;gap:6px;min-width:0;">' +
                '<span style="width:7px;height:7px;border-radius:50%;background:' + (muted ? '#ef4444' : '#10b981') + ';display:inline-block;"></span>' +
                '<span style="color:#fff;font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + _escHtml(p.user_name) + '</span>' +
            '</div>' +
            '<span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:' + (isMod ? 'rgba(13,148,136,0.25)' : 'rgba(255,255,255,0.1)') + ';color:' + (isMod ? '#2dd4bf' : 'rgba(255,255,255,0.7)') + ';">' + (isMod ? 'MODERATOR' : (muted ? 'MUTED' : 'LIVE')) + '</span>' +
        '</div>' +
    '</div>';
}

// Legacy alias (kept for any remaining call sites)
function _gvParticipantTile(p) {
    return _gvParticipantTileHTML(p);
}

function _escHtml(str) {
    return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ============================================================
// GROUP VIDEO WEBRTC MESH PEER CONNECTIONS
// ============================================================

/**
 * Called on every status poll result.
 * Creates peer connections for any new participants and closes them for
 * participants who have left.
 */
function _gvHandleParticipantChanges(participants) {
    if (!GV.myUserId) return;

    const currentIds = new Set(participants.map(function(p) { return parseInt(p.user_id, 10); }));

    // Close connections for participants who left
    Object.keys(GV.peers).forEach(function(pid) {
        if (!currentIds.has(parseInt(pid, 10))) {
            console.log('[GV] Peer left, closing PC for', pid);
            if (GV.peers[pid]) { GV.peers[pid].close(); }
            delete GV.peers[pid];
            delete GV.remoteStreams[pid];
            GV.knownPeers.delete(parseInt(pid, 10));
        }
    });

    // Create connections for new participants (excluding myself)
    participants.forEach(function(p) {
        var peerId = parseInt(p.user_id, 10);
        if (peerId === GV.myUserId) return;
        if (GV.knownPeers.has(peerId)) return;

        GV.knownPeers.add(peerId);
        _gvCreatePeerConnection(peerId, p);
    });
}

/**
 * Creates an RTCPeerConnection to a specific remote peer.
 * The participant with the LOWER user_id acts as caller (sends offer).
 */
async function _gvCreatePeerConnection(peerId, peerInfo) {
    console.log('[GV] Creating peer connection to', peerId);

    var pc = new RTCPeerConnection(GV_ICE_CONFIG);
    GV.peers[peerId] = pc;

    // Add local tracks
    if (GV.stream) {
        GV.stream.getTracks().forEach(function(track) {
            pc.addTrack(track, GV.stream);
        });
    }

    // On remote track — attach to that peer's video tile
    pc.ontrack = function(evt) {
        console.log('[GV] Got remote track from', peerId, evt.track.kind);
        if (!GV.remoteStreams[peerId]) {
            GV.remoteStreams[peerId] = new MediaStream();
        }
        GV.remoteStreams[peerId].addTrack(evt.track);

        var vid = document.getElementById('gv_vid_' + peerId);
        if (vid) {
            vid.srcObject = GV.remoteStreams[peerId];
            vid.play().catch(function() {});
            // Hide avatar overlay once stream arrives
            var av = document.getElementById('gv_vid_' + peerId + '_av');
            if (av) av.style.display = 'none';
        }
    };

    // On ICE candidate — send to remote peer via signaling
    pc.onicecandidate = function(evt) {
        if (!evt.candidate) return;
        apiRequest('video/group_signal.php', 'POST', {
            room_name:   GV.roomName,
            target_id:   peerId,
            signal_type: 'ice-candidate',
            payload:     evt.candidate.toJSON()
        }).catch(function() {});
    };

    pc.onconnectionstatechange = function() {
        console.log('[GV] Connection state with', peerId, ':', pc.connectionState);
    };

    // Lower user_id = caller (creates and sends offer)
    if (GV.myUserId < peerId) {
        try {
            var offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            await apiRequest('video/group_signal.php', 'POST', {
                room_name:   GV.roomName,
                target_id:   peerId,
                signal_type: 'offer',
                payload:     { type: offer.type, sdp: offer.sdp }
            });
            console.log('[GV] Sent offer to', peerId);
        } catch (e) {
            console.error('[GV] Error creating offer for', peerId, e);
        }
    }
    // Higher user_id = callee — waits for offer to arrive via signal poll
}

/**
 * Start/stop polling for incoming WebRTC signals every 2.5s
 */
function _gvStartSignalPoll() {
    _gvStopSignalPoll();
    // Poll immediately then every 2.5s
    _gvPollGroupSignals();
    GV.signalPollTimer = setInterval(_gvPollGroupSignals, 2500);
}
function _gvStopSignalPoll() {
    if (GV.signalPollTimer) { clearInterval(GV.signalPollTimer); GV.signalPollTimer = null; }
}

/**
 * Fetch signals addressed to me and process each one.
 */
async function _gvPollGroupSignals() {
    if (!GV.myUserId) return;
    try {
        var data = await apiRequest('video/group_signal.php?room_name=' + encodeURIComponent(GV.roomName));
        if (!data || !data.success || !data.signals) return;
        for (var i = 0; i < data.signals.length; i++) {
            await _gvHandleIncomingSignal(data.signals[i]);
        }
    } catch (e) {
        console.warn('[GV] Signal poll error:', e);
    }
}

/**
 * Process a single incoming signal (offer / answer / ice-candidate).
 */
async function _gvHandleIncomingSignal(sig) {
    var senderId = parseInt(sig.sender_id, 10);
    var payload  = sig.payload;
    var sigType  = sig.signal_type;

    console.log('[GV] Received signal', sigType, 'from', senderId);

    // Ensure we have a peer connection object for this sender
    if (!GV.peers[senderId]) {
        // We are the callee — create PC without sending offer
        GV.knownPeers.add(senderId);
        _gvCreatePeerConnection(senderId, { user_id: senderId });
        // Wait a tick for the PC to be stored
        await new Promise(function(r) { setTimeout(r, 50); });
    }

    var pc = GV.peers[senderId];
    if (!pc) { console.warn('[GV] No PC found for', senderId); return; }

    try {
        if (sigType === 'offer') {
            await pc.setRemoteDescription(new RTCSessionDescription(payload));
            var answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await apiRequest('video/group_signal.php', 'POST', {
                room_name:   GV.roomName,
                target_id:   senderId,
                signal_type: 'answer',
                payload:     { type: answer.type, sdp: answer.sdp }
            });
            console.log('[GV] Sent answer to', senderId);

        } else if (sigType === 'answer') {
            if (pc.signalingState === 'have-local-offer') {
                await pc.setRemoteDescription(new RTCSessionDescription(payload));
            }

        } else if (sigType === 'ice-candidate') {
            if (payload && payload.candidate) {
                await pc.addIceCandidate(new RTCIceCandidate(payload));
            }
        }
    } catch (e) {
        console.error('[GV] Error handling signal', sigType, 'from', senderId, e);
    }
}

function gvToggleMute() {
    GV.isMuted = !GV.isMuted;
    if (GV.stream) {
        GV.stream.getAudioTracks().forEach(function(t) { t.enabled = !GV.isMuted; });
    }
    _gvUpdateMuteUI();
}

function gvToggleCam() {
    GV.isCamOff = !GV.isCamOff;
    _gvUpdateCamUI();
}

function _gvUpdateMuteUI() {
    var btn  = document.getElementById('gvMuteBtn');
    var icon = document.getElementById('gvMuteIcon');
    if (!btn || !icon) return;
    btn.style.background = GV.isMuted ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.15)';
    icon.setAttribute('data-lucide', GV.isMuted ? 'mic-off' : 'mic');
    if (window.lucide) lucide.createIcons();
}

function _gvUpdateCamUI() {
    var btn  = document.getElementById('gvCamBtn');
    var icon = document.getElementById('gvCamIcon');
    if (btn) btn.style.background = GV.isCamOff ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.15)';
    if (icon) icon.setAttribute('data-lucide', GV.isCamOff ? 'video-off' : 'video');
    if (window.lucide) lucide.createIcons();

    // Toggle self-view tile visibility
    const localVid = document.getElementById('gvLocalVideo');
    const camOffOverlay = document.getElementById('gvMyVideoCamOff');
    if (localVid) localVid.style.display = GV.isCamOff ? 'none' : 'block';
    if (camOffOverlay) camOffOverlay.style.display = GV.isCamOff ? 'flex' : 'none';
    if (GV.stream) GV.stream.getVideoTracks().forEach(function(t) { t.enabled = !GV.isCamOff; });
}

async function gvUpdateTopic() {
    var input = document.getElementById('gvNewTopicInput');
    var topic = input ? input.value.trim() : '';
    if (!topic) { alert('Please enter a topic first.'); return; }
    try {
        var data = await apiRequest('video/group_room.php', 'POST', { action: 'moderator_control', sub_action: 'set_topic', topic: topic });
        if (data && data.success) {
            var topicEl = document.getElementById('gvTopicText');
            if (topicEl) topicEl.textContent = 'Topic: ' + topic;
            if (input) input.value = '';
            alert('✅ Topic updated to: ' + topic);
        } else {
            alert((data && data.message) || 'Failed to update topic.');
        }
    } catch (e) {
        console.error('gvUpdateTopic error:', e);
        alert('Network error updating topic.');
    }
}

async function gvMuteAllStudents() {
    if (!confirm('Mute all student participants?')) return;
    try {
        var data = await apiRequest('video/group_room.php', 'POST', { action: 'moderator_control', sub_action: 'mute_all' });
        if (data && data.success) {
            alert('🔇 All student participants have been muted.');
        } else {
            alert((data && data.message) || 'Failed to mute participants.');
        }
    } catch (e) {
        console.error('gvMuteAllStudents error:', e);
        alert('Network error muting students.');
    }
}

async function gvEndDiscussionForEveryone() {
    if (!confirm('End the discussion for ALL participants? This will close the room for everyone.')) return;
    try {
        var data = await apiRequest('video/group_room.php', 'POST', { action: 'moderator_control', sub_action: 'end_room' });
        if (data && data.success) {
            alert('✅ Discussion session ended for all participants.');
            leaveGroupVideoRoom();
        } else {
            alert((data && data.message) || 'Failed to end session.');
        }
    } catch (e) {
        console.error('gvEndDiscussionForEveryone error:', e);
        alert('Network error ending discussion.');
    }
}

async function leaveGroupVideoRoom() {
    _gvStopHeartbeat();
    _gvStopStatusPoll();
    _gvStopSignalPoll();

    // Close all active WebRTC peer connections
    if (GV.peers) {
        Object.keys(GV.peers).forEach(function(pid) {
            if (GV.peers[pid]) {
                try { GV.peers[pid].close(); } catch(_) {}
            }
        });
        GV.peers = {};
    }
    GV.remoteStreams = {};
    if (GV.knownPeers) GV.knownPeers.clear();
    GV.myUserId = null;

    if (GV.stream) {
        GV.stream.getTracks().forEach(function(t) { t.stop(); });
        GV.stream = null;
    }

    try {
        await apiRequest('video/group_room.php', 'POST', { action: 'leave' });
    } catch (_) {}

    GV.roomId = null;
    GV.isModerator = false;

    var modal = document.getElementById('groupVideoDiscussionModal');
    if (modal) modal.style.display = 'none';
}

/**
 * Counselor initiates & calls all students for Group Video Discussion
 */
async function gvInviteAllStudentsCall() {
    try {
        const data = await apiRequest('video/group_room.php', 'POST', { action: 'moderator_control', sub_action: 'invite_all' });
        if (data && data.success) {
            alert('📞 Group call invitation sent to all students! They will receive a live incoming call prompt on their dashboard.');
        } else {
            alert((data && data.message) || 'Failed to send call invitation.');
        }
    } catch (e) {
        console.error('gvInviteAllStudentsCall error:', e);
        alert('Network error sending invitation.');
    }
}

/**
 * Poll for active incoming group video calls (WhatsApp style) for student
 */
let incomingCallDeclined = false;
async function checkIncomingGroupCall() {
    if (!App.user || App.user.role !== 'student' || incomingCallDeclined) return;

    // Don't show if already inside group modal
    const groupModal = document.getElementById('groupVideoDiscussionModal');
    if (groupModal && groupModal.style.display !== 'none') return;

    try {
        const res = await fetch('video/group_room.php?action=check_call_invite');
        const data = await res.json();
        if (data.success && data.active_call) {
            const call = data.active_call;
            const counselorName = call.moderator_name || 'Counselor Moderator';

            // Show floating popup
            const modal = document.getElementById('incomingGroupCallModal');
            const nameEl = document.getElementById('incomingCallCounselorName');
            if (nameEl) nameEl.textContent = counselorName;
            if (modal) modal.style.display = 'block';

            // ALSO show banner inside the discussions tab
            const banner = document.getElementById('discussionCallInviteBanner');
            const bannerName = document.getElementById('discussionCallCounselorName');
            if (banner) banner.style.display = 'flex';
            if (bannerName) bannerName.textContent = counselorName;

        } else {
            // Hide both if no active call
            const modal = document.getElementById('incomingGroupCallModal');
            if (modal) modal.style.display = 'none';
            // Only auto-hide banner if not declined
            if (!incomingCallDeclined) {
                const banner = document.getElementById('discussionCallInviteBanner');
                if (banner) banner.style.display = 'none';
            }
        }
    } catch (e) {}
}

function acceptIncomingGroupCall() {
    const modal = document.getElementById('incomingGroupCallModal');
    if (modal) modal.style.display = 'none';
    hideChatInviteBanner();
    openGroupVideoDiscussionRoom();
}

function declineIncomingGroupCall() {
    incomingCallDeclined = true;
    const modal = document.getElementById('incomingGroupCallModal');
    if (modal) modal.style.display = 'none';
    hideChatInviteBanner();
}

function hideChatInviteBanner() {
    const b = document.getElementById('discussionCallInviteBanner');
    if (b) b.style.display = 'none';
}




