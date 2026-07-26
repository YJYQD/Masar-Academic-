(function () {
    const collegesSelect = document.getElementById('colleges');
    const departmentsSelect = document.getElementById('depts');
    const addDoctorCollegeSelect = document.getElementById('college');
    const addDoctorDepartmentSelect = document.getElementById('department');
    const searchCollegesSelect = document.getElementById('search-colleges');
    const searchDepartmentsSelect = document.getElementById('search-depts');
    const searchGenderSelect = document.getElementById('search-gender');
    const searchForm = document.getElementById('live-search-form');
    const searchInput = document.getElementById('search-input');
    const resultsRoot = document.getElementById('results');
    const cardsContainer = resultsRoot ? resultsRoot.querySelector('.cards') : null;
    const adminToasts = document.getElementById('admin-toasts');
    const adminFilterCollege = document.getElementById('admin-filter-college');
    const adminFilterDept = document.getElementById('admin-filter-dept');
    const map = window.departmentsMap || {};
    const csrfToken = window.csrfToken || '';
    const isAdmin = Boolean(window.isAdmin);
    const adminNotificationsUrl = window.adminNotificationsUrl || '';
    const isUser = Boolean(window.isUser);
    const currentUserName = window.currentUserName || '';
    let searchTimer = null;
    let fingerprintPromise = null;
    let lastPendingCount = 0;
    let lastPendingReviewId = 0;

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#96;');
    }

    function sentimentLabelArabic(value) {
        switch (String(value || 'neutral')) {
            case 'positive':
                return 'إيجابي';
            case 'negative':
                return 'سلبي';
            default:
                return 'محايد';
        }
    }

    function sentimentClass(value) {
        return `review-sentiment--${String(value || 'neutral')}`;
    }

    function doctorGenderLabel(value) {
        switch (String(value || '')) {
            case 'male':
                return 'دكتور';
            case 'female':
                return 'دكتورة';
            default:
                return 'غير محدد';
        }
    }

    function renderRatingStars(ratingValue) {
        const filled = Math.max(0, Math.min(5, Math.round(Number(ratingValue || 0))));
        const stars = [];
        for (let index = 1; index <= 5; index += 1) {
            stars.push(`<span class="rating-stars__star ${index <= filled ? 'is-filled' : ''}">★</span>`);
        }
        return stars.join('');
    }

    function buildFingerprintSeed() {
        const parts = [
            navigator.userAgent || '',
            navigator.language || '',
            navigator.languages ? navigator.languages.join(',') : '',
            String(screen.width || 0),
            String(screen.height || 0),
            String(screen.colorDepth || 0),
            String(Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
            String(navigator.platform || ''),
            String(navigator.hardwareConcurrency || 0),
            String(navigator.maxTouchPoints || 0),
        ];

        return parts.join('|');
    }

    function toHex(buffer) {
        return Array.from(new Uint8Array(buffer)).map(function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    // توليد بصمة رقمية متطورة لمنع التقييم العشوائي والتكرار المخرب
    function getBrowserFingerprint() {
        if (fingerprintPromise) {
            return fingerprintPromise;
        }

        fingerprintPromise = Promise.resolve().then(function () {
            const seed = buildFingerprintSeed();
            if (window.crypto && window.crypto.subtle && window.TextEncoder) {
                return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(seed)).then(function (hash) {
                    return toHex(hash);
                });
            }

            return seed;
        });

        return fingerprintPromise;
    }

    function fillFingerprintFields(scope) {
        const root = scope || document;
        const fields = root.querySelectorAll('[data-fingerprint-field]');
        if (!fields.length) {
            return;
        }

        getBrowserFingerprint().then(function (fingerprint) {
            fields.forEach(function (field) {
                field.value = fingerprint;
            });
        });
    }

    function fillDepartmentsFor(collegeSelect, departmentSelect, emptyLabel, preserveValue) {
        if (!collegeSelect || !departmentSelect) {
            return;
        }

        const selectedCollege = String(collegeSelect.value || '').trim();
        const selectedCollegeText = (collegeSelect.options && collegeSelect.options[collegeSelect.selectedIndex] ? String(collegeSelect.options[collegeSelect.selectedIndex].text || '') : '').trim();
        const desiredValue = typeof preserveValue === 'string' && preserveValue !== '' ? preserveValue : (departmentSelect.dataset.currentValue || '');

        function norm(s) {
            return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        let departments = (selectedCollege !== '' ? (map[selectedCollege] || []) : []);
        if ((!departments || departments.length === 0) && selectedCollegeText !== '') {
            departments = map[selectedCollegeText] || [];
        }
        if ((!departments || departments.length === 0) && selectedCollege !== '') {
            const keys = Object.keys(map || {});
            const foundKey = keys.find(function(k){ return norm(k) === norm(selectedCollege); });
            if (foundKey) {
                departments = map[foundKey] || [];
            } else {
                const partial = keys.find(function(k){ return norm(k).includes(norm(selectedCollege)) || norm(selectedCollege).includes(norm(k)); });
                if (partial) {
                    departments = map[partial] || [];
                }
            }
        }

        departmentSelect.innerHTML = '';
        departmentSelect.disabled = departments.length === 0;

        if (departments.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = emptyLabel || 'اختر الكلية أولًا';
            departmentSelect.appendChild(option);
            return;
        }

        departmentSelect.disabled = false;

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = emptyLabel || 'اختر القسم';
        departmentSelect.appendChild(defaultOption);

        departments.forEach(function (department) {
            const option = document.createElement('option');
            option.value = department;
            option.textContent = department;
            departmentSelect.appendChild(option);
        });

        if (desiredValue) {
            const matchedOption = Array.from(departmentSelect.options).find(function (option) {
                return String(option.value || '').trim() === String(desiredValue).trim();
            });
            if (matchedOption) {
                departmentSelect.value = desiredValue;
            }
        }
    }

    function fillDepartments() {
        fillDepartmentsFor(collegesSelect, departmentsSelect, 'اختر القسم');
    }

    function fillAddDoctorDepartments() {
        if (!addDoctorCollegeSelect || !addDoctorDepartmentSelect) {
            return;
        }
        fillDepartmentsFor(addDoctorCollegeSelect, addDoctorDepartmentSelect, 'اختر القسم', addDoctorDepartmentSelect.dataset.currentValue || '');
    }

    window.updateAddDoctorDepartments = fillAddDoctorDepartments;

    // Ensure the add-doctor college select triggers department population
    if (addDoctorCollegeSelect && addDoctorDepartmentSelect) {
        try {
            addDoctorCollegeSelect.addEventListener('change', fillAddDoctorDepartments);
            // populate once on load if a value is already selected
            if (String(addDoctorCollegeSelect.value || '').trim() !== '') {
                fillAddDoctorDepartments();
            }
        } catch (e) {
            console.debug('[debug] failed to bind add-doctor college change listener', e);
        }
    }

    function fillSearchDepartments() {
        if (!searchCollegesSelect || !searchDepartmentsSelect) {
            return;
        }

        const currentDepartment = searchForm ? (searchForm.dataset.currentDepartment || '') : '';
        searchDepartmentsSelect.dataset.currentValue = currentDepartment;
        fillDepartmentsFor(searchCollegesSelect, searchDepartmentsSelect, 'القسم: الكل', currentDepartment);
    }

    function updateStarGroup(group) {
        const inputs = Array.from(group.querySelectorAll('input[type="radio"]'));
        const labels = Array.from(group.querySelectorAll('label'));
        const checkedInput = inputs.find(function (input) {
            return input.checked;
        });
        const selectedValue = checkedInput ? parseInt(checkedInput.value, 10) : 0;

        labels.forEach(function (label, index) {
            label.classList.toggle('is-active', index < selectedValue);
        });
    }

    function setupStarGroups(scope) {
        const root = scope || document;
        const groups = root.querySelectorAll('[data-rating-group]');

        groups.forEach(function (group) {
            if (group.dataset.bound === '1') {
                updateStarGroup(group);
                return;
            }

            group.dataset.bound = '1';
            group.addEventListener('change', function () {
                updateStarGroup(group);
            });
            updateStarGroup(group);
        });
    }

    function renderCharts(scope) {
        if (!window.Chart) {
            return;
        }

        const root = scope || document;
        const comparisonCanvas = root.querySelector('canvas.comparison-chart');
        if (comparisonCanvas) {
            const chartStore = renderCharts._chartStore || (renderCharts._chartStore = new WeakMap());
            const previousChart = chartStore.get(comparisonCanvas);
            if (previousChart) {
                previousChart.destroy();
            }

            let labels = [];
            let values = [];

            try {
                labels = JSON.parse(comparisonCanvas.dataset.comparisonLabels || '[]');
            } catch (error) {
                labels = [];
            }

            try {
                values = JSON.parse(comparisonCanvas.dataset.comparisonValues || '[]');
            } catch (error) {
                values = [];
            }

            const chart = new window.Chart(comparisonCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels.length ? labels : ['لا توجد بيانات'],
                    datasets: [{
                        label: 'متوسط التقييم',
                        data: values.length ? values : [0],
                        backgroundColor: '#0a7e8c',
                        borderRadius: 12,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false,
                        },
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            max: 5,
                        },
                    },
                },
            });

            chartStore.set(comparisonCanvas, chart);
        }
    }

    function renderReviews(reviews) {
        if (!reviews || reviews.length === 0) {
            return '<p class="empty small">لا توجد تقييمات لهذا الدكتور حتى الآن.</p>';
        }

        return reviews.map(function (review) {
            const rating = Math.max(0, Math.min(5, parseInt(review.rating, 10) || 0));
            const reviewerName = review.reviewer_name ? review.reviewer_name : 'طالب';
            const courseLine = `${review.course_code || ''} - ${review.semester || ''}`;
            const sentiment = review.sentiment || 'neutral';
            
            // Build stars display with proper HTML
            let starsHtml = '<div class="rating-stars" aria-label="تقييم ' + rating + ' من 5">';
            for (let i = 1; i <= 5; i++) {
                const isFilled = i <= rating ? ' is-filled' : '';
                starsHtml += '<span class="rating-stars__star' + isFilled + '">★</span>';
            }
            starsHtml += '</div>';

            return [
                '<div class="review-item">',
                '<p class="review-item__meta">',
                starsHtml,
                `<strong>${escapeHtml(reviewerName)}</strong>`,
                `<span class="review-sentiment ${escapeAttr(sentimentClass(sentiment))}">${escapeHtml(sentimentLabelArabic(sentiment))}</span>`,
                '</p>',
                `<p class="review-item__course">${escapeHtml(courseLine)}</p>`,
                `<p>${escapeHtml(review.comment || '').replace(/\n/g, '<br>')}</p>`,
                '</div>',
            ].join('');
        }).join('');
    }

    function renderDoctorCard(doc) {
        const avgRating = Number(doc.avg_rating || 0).toFixed(1);
        const reviewCount = Number(doc.review_count || 0);
        const doctorId = Number(doc.id || 0);
        const reviews = Array.isArray(doc.reviews) ? doc.reviews : [];

        const parts = [];
        parts.push('<article class="doctor-card">');
        parts.push('<div class="doctor-card__head">');
        parts.push('<div>');
        parts.push(`<h3>${escapeHtml(doc.name || '')} <small class="doctor-badge">${escapeHtml(doctorGenderLabel(doc.gender))}</small></h3>`);
        parts.push(`<p>${escapeHtml(`كلية ${doc.college || 'غير محددة'} - ${doc.department || 'غير محدد'}`)}</p>`);
        parts.push('</div>');
        parts.push('<div class="rating-pill">');
        parts.push(`<strong>${escapeHtml(avgRating)}</strong>`);
        parts.push(`<div class="rating-stars" aria-label="التقييم ${escapeHtml(avgRating)} من 5">${renderRatingStars(doc.avg_rating)}</div>`);
        parts.push('<span>من 5</span>');
        parts.push(`<small>${reviewCount} تقييم</small>`);
        parts.push('</div>');
        parts.push('</div>');
        parts.push('<div class="doctor-card__meta">');
        parts.push('<span class="meta-chip meta-chip--highlight">');
        parts.push(`<strong>${escapeHtml(avgRating)}</strong>`);
        parts.push('<span>متوسط التقييم</span>');
        parts.push('</span>');
        parts.push('<span class="meta-chip">');
        parts.push(`<strong>${reviewCount}</strong>`);
        parts.push('<span>تقييمات</span>');
        parts.push('</span>');
        parts.push('<span class="meta-chip">');
        parts.push(`<strong>${escapeHtml(doctorGenderLabel(doc.gender))}</strong>`);
        parts.push('<span>النوع</span>');
        parts.push('</span>');
        parts.push('</div>');
        parts.push('<div class="reviews">');
        parts.push(renderReviews(reviews));
        parts.push('</div>');

        if (isUser) {
            parts.push('<form method="POST" action="save.php" class="review-form">');
            parts.push(`<input type="hidden" name="csrf_token" value="${escapeAttr(csrfToken)}">`);
            parts.push(`<input type="hidden" name="return_to" value="index.php">`);
            parts.push(`<input type="hidden" name="doc_id" value="${doctorId}">`);
            parts.push('<input type="hidden" name="review_action" value="add_review">');
            parts.push('<input type="hidden" name="browser_fingerprint" data-fingerprint-field value="">');
            parts.push('<label>');
            parts.push('اسم الحساب');
            parts.push(`<input type="text" value="${escapeAttr(currentUserName)}" readonly>`);
            parts.push('</label>');
            parts.push('<label>');
            parts.push('كود المادة');
            parts.push('<input type="text" name="course_code" placeholder="مثال: COMP-111" required>');
            parts.push('</label>');
            parts.push('<label>');
            parts.push('الفصل الدراسي');
            parts.push('<input type="text" name="semester" placeholder="مثال: الفصل الأول 1447هـ" required>');
            parts.push('</label>');
            parts.push('<div class="field-group">');
            parts.push('<span class="field-label">التقييم</span>');
            parts.push('<div class="star-rating" data-rating-group>');
            parts.push(`<input type="radio" id="rating-${doctorId}-5" name="rating" value="5" required>`);
            parts.push(`<label for="rating-${doctorId}-5">★</label>`);
            parts.push(`<input type="radio" id="rating-${doctorId}-4" name="rating" value="4">`);
            parts.push(`<label for="rating-${doctorId}-4">★</label>`);
            parts.push(`<input type="radio" id="rating-${doctorId}-3" name="rating" value="3">`);
            parts.push(`<label for="rating-${doctorId}-3">★</label>`);
            parts.push(`<input type="radio" id="rating-${doctorId}-2" name="rating" value="2">`);
            parts.push(`<label for="rating-${doctorId}-2">★</label>`);
            parts.push(`<input type="radio" id="rating-${doctorId}-1" name="rating" value="1">`);
            parts.push(`<label for="rating-${doctorId}-1">★</label>`);
            parts.push('</div>');
            parts.push('</div>');
            parts.push('<label class="full-width">');
            parts.push('التعليق');
            parts.push('<textarea name="comment" rows="3" placeholder="اكتب تقييمك بشكل محترم وموضوعي" required></textarea>');
            parts.push('</label>');
            parts.push('<label class="toggle full-width">');
            parts.push('<input type="checkbox" name="is_anonymous" value="1">');
            parts.push('<span class="toggle__slider"></span>');
            parts.push('<span class="toggle__label">تقييم باسم مجهول</span>');
            parts.push('</label>');
            parts.push('<button type="submit" name="add_review" value="1" class="btn btn--accent full-width">إرسال التقييم</button>');
            parts.push('</form>');
        } else {
            parts.push('<div class="login-prompt" style="padding: 10px; margin-top: 10px; color:#6b7280;">');
            parts.push('<p>يجب تسجيل الدخول لإرسال تقييم. <a href="login" style="color:var(--accent); font-weight:bold;">تسجيل الدخول</a> أو <a href="register" style="color:var(--accent); font-weight:bold;">إنشاء حساب</a></p>');
            parts.push('</div>');
        }

        parts.push('</article>');
        return parts.join('');
    }

    function renderSearchResults(results) {
        if (!cardsContainer) {
            return;
        }

        if (!results || results.length === 0) {
            cardsContainer.innerHTML = '<p class="empty">لا توجد نتائج حالياً. جرّب إضافة دكتور أو غيّر كلمات البحث.</p>';
            refreshDynamicParts(cardsContainer);
            return;
        }

        cardsContainer.innerHTML = results.map(renderDoctorCard).join('');
        refreshDynamicParts(cardsContainer);
    }

    function refreshDynamicParts(scope) {
        setupStarGroups(scope);
        renderCharts(scope);
        fillFingerprintFields(scope);
        setupAdminActions(scope);
    }

    function showAdminToast(message, tone) {
        if (!adminToasts) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = `toast toast--${tone || 'info'}`;
        toast.textContent = message;
        adminToasts.appendChild(toast);

        window.setTimeout(function () {
            toast.classList.add('toast--visible');
        }, 10);

        window.setTimeout(function () {
            toast.classList.remove('toast--visible');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, 4200);
    }

    function pollAdminNotifications() {
        if (!isAdmin || !adminNotificationsUrl) {
            return;
        }

        window.fetch(adminNotificationsUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('notification request failed');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    return;
                }

                const pendingCount = Number(payload.pending_count || 0);
                const latestPending = Array.isArray(payload.latest_pending) ? payload.latest_pending : [];
                const newestId = latestPending.length > 0 ? Number(latestPending[0].id || 0) : 0;

                if (lastPendingCount !== 0 && pendingCount > lastPendingCount) {
                    showAdminToast(`وصلت ${pendingCount - lastPendingCount} مراجعة جديدة بانتظارك`, 'info');
                }

                if (lastPendingReviewId !== 0 && newestId > lastPendingReviewId) {
                    const newest = latestPending[0];
                    showAdminToast(`تقييم جديد معلق: ${newest.doctor_name || 'دكتور'}`, 'success');
                }

                lastPendingCount = pendingCount;
                lastPendingReviewId = newestId;
            })
            .catch(function () {
                // تجاهل أخطاء الشبكة المؤقتة
            });
    }

    // لقط معالجات الحذف والاعتماد عبر الأياكس وتحديث الواجهة حياً دون تفريغ الصفحة
    function triggerAcademySync() {
        const syncUrl = '/curriculum.php?api=academy_sync';
        fetch(syncUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        }).catch(function () {
            // ignore sync failures
        });

        if (window.refreshCurriculumView) {
            window.refreshCurriculumView();
        }
        if (window.refreshSyllabusView) {
            window.refreshSyllabusView();
        }
    }

    function setupAdminActions(scope) {
        const root = scope || document;
        const forms = Array.from(root.querySelectorAll('form.admin-actions'));
        forms.forEach(function (form) {
            if (form.dataset.bound === '1') return;
            form.dataset.bound = '1';
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                const submitter = ev.submitter || form.querySelector('[type="submit"]');
                const confirmMsg = submitter && submitter.dataset && submitter.dataset.confirm ? submitter.dataset.confirm : null;
                if (confirmMsg) {
                    if (!window.confirm(confirmMsg)) {
                        return;
                    }
                }

                const fd = new FormData(form);
                if (submitter && submitter.name && submitter.value) {
                    fd.append(submitter.name, submitter.value);
                }
                if (!fd.has('csrf_token') && window.csrfToken) {
                    fd.append('csrf_token', window.csrfToken);
                }

                const buttons = Array.from(form.querySelectorAll('button'));
                buttons.forEach(function (b) { b.disabled = true; });

                const targetUrl = form.getAttribute('action') || window.location.href;

                fetch(targetUrl, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                }).then(function (res) {
                    if (!res.ok) throw new Error('network');
                    return res.json();
                }).then(function (data) {
                    if (data && data.success === true) {
                        showAdminToast((data.msg && String(data.msg)) || 'تمت العملية بنجاح وبشكل فوري', 'success');
                        triggerAcademySync();
                        const article = form.closest('.admin-review-item') || form.closest('.admin-doctor-item') || form.closest('article');
                        if (article) {
                            article.remove();
                        } else {
                            // if server indicates reload is needed or no removable element, reload
                            if (data.reload === true) {
                                window.location.reload();
                            } else {
                                // try to refresh dynamic parts
                                refreshDynamicParts(document);
                            }
                        }
                    } else {
                        const msg = (data && data.msg) ? String(data.msg) : 'فشل تنفيذ الإجراء، يرجى إعادة المحاولة';
                        showAdminToast(msg, 'danger');
                    }
                }).catch(function () {
                    showAdminToast('فشل تنفيذ الإجراء، يرجى إعادة المحاولة', 'danger');
                }).finally(function () {
                    buttons.forEach(function (b) { b.disabled = false; });
                });
            });
        });
    }

    // Hook: when subject modal form submits via AJAX, close modal and refresh section
    document.addEventListener('submit', function (ev) {
        const form = ev.target;
        if (!form || !form.classList || !form.classList.contains('admin-actions')) return;
        // we let the admin-actions handler handle it; listen for successful responses via fetch in that handler
    });

    function updateSearchResults() {
        if (!searchInput || !resultsRoot || !cardsContainer) {
            return;
        }

        const formData = searchForm ? new FormData(searchForm) : new FormData();
        const url = new URL(window.location.href);

        const apiUrl = new URL(window.location.href);
        apiUrl.searchParams.set('api', 'search');

        formData.forEach(function (value, key) {
            const stringValue = String(value || '').trim();
            if (stringValue) {
                apiUrl.searchParams.set(key, stringValue);
            } else {
                apiUrl.searchParams.delete(key);
            }
        });

        window.fetch(apiUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    return;
                }

                renderSearchResults(payload.results || []);
                const queryParts = [];
                formData.forEach(function (value, key) {
                    const stringValue = String(value || '').trim();
                    if (stringValue) {
                        queryParts.push(`${encodeURIComponent(key)}=${encodeURIComponent(stringValue)}`);
                    }
                });
                window.history.replaceState({}, '', url.pathname + (queryParts.length ? `?${queryParts.join('&')}` : ''));
            })
            .catch(function () {
                // إبقاء السلوك الافتراضي في حال الفشل الموقت
            });
    }

    if (collegesSelect) {
        collegesSelect.addEventListener('change', fillDepartments);
        fillDepartments();
    }

    if (addDoctorCollegeSelect) {
        addDoctorCollegeSelect.addEventListener('change', fillAddDoctorDepartments);
        fillAddDoctorDepartments();
    }

    if (searchCollegesSelect) {
        searchCollegesSelect.addEventListener('change', function () {
            fillSearchDepartments();
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(updateSearchResults, 120);
        });
        fillSearchDepartments();
    }

    if (adminFilterCollege) {
        adminFilterCollege.addEventListener('change', function () {
            fillDepartmentsFor(adminFilterCollege, adminFilterDept, 'القسم: الكل');
        });
        fillDepartmentsFor(adminFilterCollege, adminFilterDept, 'القسم: الكل');
        if (adminFilterDept && adminFilterDept.dataset && adminFilterDept.dataset.currentDepartment) {
            try { adminFilterDept.value = adminFilterDept.dataset.currentDepartment; } catch (e) {}
        }
    }

    if (searchForm && searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(updateSearchResults, 220);
        });

        searchForm.addEventListener('change', function (event) {
            if (event.target && event.target.id === 'search-gender') {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(updateSearchResults, 120);
                return;
            }

            if (event.target && event.target.id === 'search-depts') {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(updateSearchResults, 120);
            }
        });
    }

    refreshDynamicParts(document);

    if (isAdmin) {
        pollAdminNotifications();
        window.setInterval(pollAdminNotifications, 15000);
    }

    function createPlaceholderModal(title, message) {
        const root = document.getElementById('syllabus-coming-soon-modal-root');
        if (!root) {
            return;
        }
        root.innerHTML = '';
        const backdrop = document.createElement('div');
        backdrop.style.position = 'fixed';
        backdrop.style.inset = '0';
        backdrop.style.background = 'rgba(0,0,0,0.6)';
        backdrop.style.display = 'flex';
        backdrop.style.alignItems = 'center';
        backdrop.style.justifyContent = 'center';
        backdrop.style.zIndex = '2000';
        const modal = document.createElement('div');
        modal.style.background = '#fff';
        modal.style.padding = '24px';
        modal.style.borderRadius = '14px';
        modal.style.maxWidth = '520px';
        modal.style.width = '90%';
        modal.style.boxShadow = '0 12px 35px rgba(0, 0, 0, 0.18)';
        modal.innerHTML = `
            <h2 style="margin-top:0; font-size:1.3rem;">${escapeHtml(title)}</h2>
            <p style="margin: 14px 0 0; line-height:1.6;">${escapeHtml(message)}</p>
            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn btn--accent" id="close-syllabus-placeholder">حسناً</button>
            </div>
        `;
        backdrop.appendChild(modal);
        root.appendChild(backdrop);
        root.style.display = 'block';

        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                root.style.display = 'none';
            }
        });

        modal.querySelector('#close-syllabus-placeholder')?.addEventListener('click', function () {
            root.style.display = 'none';
        });
    }

    function setupSyllabusButton() {
        const syllabusButtons = document.querySelectorAll('.open-syllabus-coming-soon');
        syllabusButtons.forEach(function(btn) {
            btn.addEventListener('click', function () {
                createPlaceholderModal('الخطة الدراسية', 'هذه الميزة قيد الإعداد. ستُضاف تفاصيل الخطة الدراسية قريباً.');
            });
        });
    }

    setupSyllabusButton();

    // Global: show spinner on submit for forms with `.with-spinner` or all forms with a submit button
    function setupFormSpinners(scope) {
        const root = scope || document;
        const forms = Array.from(root.querySelectorAll('form'));
        forms.forEach(function(form) {
            const action = String(form.getAttribute('action') || '').toLowerCase();
            const isAuthForm = form.classList.contains('auth-form') ||
                form.classList.contains('login-form') ||
                form.closest('.auth-page') ||
                action.indexOf('login_check.php') !== -1 ||
                action.indexOf('verify_otp') !== -1 ||
                action.indexOf('register') !== -1;

            if (isAuthForm || form.dataset.noSpinner === '1') {
                return;
            }

            if (form.dataset.spinnerBound === '1') return;
            form.dataset.spinnerBound = '1';
            form.addEventListener('submit', function(e){
                const submit = form.querySelector('button[type="submit"]') || form.querySelector('input[type="submit"]');
                if (!submit) return;
                // if already disabled, prevent double submit
                if (submit.disabled) {
                    e.preventDefault();
                    return;
                }
                // create spinner element
                const spinner = document.createElement('span');
                spinner.className = 'spinner';
                spinner.style.marginRight = '8px';
                submit.prepend(spinner);
                submit.disabled = true;
            });
        });
    }

    setupFormSpinners(document);
})();