import {eventBus, Events} from "./core/event-bus";
import {FilterStorage} from "./features/data-table/storage";
import Router from "./core/router";
import {updateGithubLatestRelease} from "./services/github";
import Sidebar from "./components/sidebar";
import ChartManager from "./features/charts/chart-manager";
import {registerEchartsCallbacks} from "./features/charts/echarts-callbacks";
import ModalManager from "./components/modals";
import PhotoWall from "./features/photos/photo-wall";
import LeafletMapManager from "./features/maps/map-manager";
import TabsManager from "./components/tabs";
import LazyLoad from "../libraries/lazyload.min";
import DataTableManager from "./features/data-table/data-table-manager";
import FullscreenManager from "./components/fullscreen";
import ScrollTo from "./components/scroll-to";
import Heatmap from "./features/heatmap/heatmap";
import MilestoneFilter from "./features/milestones/milestone-filter";
import DarkModeManager from "./components/dark-mode";
import WorkoutEditor from "./features/planned-session/workout-editor";
import TrainingPlanAnalysisPromptManager from "./features/training-plan/analysis-prompt";
import MobileSidebarTabs from "./components/mobile-sidebar-tabs";
import ManualSyncManager from "./features/account/manual-sync";

const $main = document.querySelector("main");

// Boot router.
const router = new Router($main);
router.boot();

registerEchartsCallbacks();

const sidebar = new Sidebar();
const modalManager = new ModalManager(router);
const chartManager = new ChartManager(router, modalManager);
const leafletMapManager = new LeafletMapManager();
const tabsManager = new TabsManager();
const dataTableManager = new DataTableManager();
const fullscreenManager = new FullscreenManager();
const scrollTo = new ScrollTo();
const darkModeManager = new DarkModeManager();
const workoutEditor = new WorkoutEditor();
const trainingPlanAnalysisPromptManager = new TrainingPlanAnalysisPromptManager();
const mobileSidebarTabs = new MobileSidebarTabs();
const manualSyncManager = new ManualSyncManager();
const lazyLoad = new LazyLoad({
    thresholds: "50px",
    callback_error: (img) => {
        img.setAttribute("src", window.statisticsForStrava.placeholderBrokenImage);
    }
});

const initElements = (rootNode) => {
    lazyLoad.update();

    tabsManager.init(rootNode);
    initPopovers();
    initTooltips();
    initDropdowns();
    initAccordions();

    modalManager.init(rootNode);
    dataTableManager.init(rootNode);
    chartManager.init(rootNode, darkModeManager.isDarkModeEnabled());
    leafletMapManager.init(rootNode);
    fullscreenManager.init(rootNode);
    scrollTo.init(rootNode);
    workoutEditor.init(rootNode);
    trainingPlanAnalysisPromptManager.init(rootNode);
    mobileSidebarTabs.init(rootNode);
    manualSyncManager.init(rootNode);
}

const maybeAutoOpenRecoveryCheckIn = (modalId) => {
    if (modalId) {
        return;
    }

    const triggerNode = document.querySelector('[data-auto-open-recovery-check-in-url]');
    if (!triggerNode) {
        return;
    }

    const recoveryCheckInModalUrl = triggerNode.getAttribute('data-auto-open-recovery-check-in-url');
    if (!recoveryCheckInModalUrl) {
        return;
    }

    modalManager.open(recoveryCheckInModalUrl);
    router.pushCurrentRouteToHistoryState(recoveryCheckInModalUrl);
}

sidebar.init();
darkModeManager.attachEventListeners();

// Arrow key navigation for month pages.
document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select, [contenteditable]')) {
        return;
    }
    // Skip when a modal is open (modal has its own handler).
    const modalSkeleton = document.getElementById('modal-skeleton');
    if (modalSkeleton && !modalSkeleton.classList.contains('hidden')) {
        return;
    }
    const content = document.getElementById('js-loaded-content');
    if (!content) {
        return;
    }
    if (e.key === 'ArrowLeft') {
        const prev = content.querySelector('a[data-nav-prev][data-router-navigate], a[data-nav-prev][data-model-content-url]');
        if (prev) {
            e.preventDefault();
            prev.click();
        }
    } else if (e.key === 'ArrowRight') {
        const next = content.querySelector('a[data-nav-next][data-router-navigate], a[data-nav-next][data-model-content-url]');
        if (next) {
            e.preventDefault();
            next.click();
        }
    }
});

eventBus.on(Events.DARK_MODE_TOGGLED, ({darkModeEnabled}) => {
    chartManager.toggleDarkTheme(darkModeEnabled);
});

eventBus.on(Events.PAGE_LOADED, async ({page, modalId}) => {
    modalManager.close();

    chartManager.reset();
    initElements(document);

    if (modalId) {
        modalManager.open(modalId);
    }

    if (page === 'milestones') {
        new MilestoneFilter(document).init();
    }
    if (page === 'heatmap') {
        const $heatmapWrapper = document.querySelector('.heatmap-wrapper');
        await new Heatmap($heatmapWrapper, modalManager).render();
    }
    if (page === 'photos') {
        const $photoWallWrapper = document.querySelector('.photo-wall-wrapper');
        await new PhotoWall($photoWallWrapper).render();
    }

    maybeAutoOpenRecoveryCheckIn(modalId);
});
eventBus.on(Events.NAVIGATION_CLICKED, ({link}) => {
    if (!link || !link.hasAttribute('data-filters')) {
        return;
    }
    const filters = JSON.parse(link.getAttribute('data-filters'));
    Object.entries(filters).forEach(([tableName, tableFilters]) => {
        FilterStorage.set(tableName, tableFilters);
    });
});

// ── Training plan wizard step navigation ──
const initTrainingPlanWizard = (node) => {
    const steps = node.querySelectorAll('[data-wizard-step]');
    if (steps.length === 0) return;

    const dots = node.querySelectorAll('[data-wizard-dot]');
    const backBtn = node.querySelector('[data-wizard-back]');
    const nextBtn = node.querySelector('[data-wizard-next]');
    const submitBtn = node.querySelector('[data-wizard-submit]');
    const wizardError = node.querySelector('[data-wizard-error]');
    const disciplineInput = node.querySelector('[data-discipline-input]');
    const typeInput = node.querySelector('[data-type-input]');
    const trainingFocusSelect = node.querySelector('[name="trainingFocus"]');
    const trainingFocusSection = node.querySelector('[data-training-focus-section]');
    const form = node.querySelector('form[id^="training-plan-form-"]');
    const totalSteps = steps.length;
    let current = 1;

    const setWizardError = (message) => {
        if (!wizardError) return;
        wizardError.textContent = message;
        wizardError.classList.remove('hidden');
    };

    const clearWizardError = () => {
        if (!wizardError) return;
        wizardError.textContent = '';
        wizardError.classList.add('hidden');
    };

    const getActiveSportPicker = (fieldName) => {
        const pickers = Array.from(node.querySelectorAll(`[data-sport-day-picker="${fieldName}"]`));
        return pickers.find((picker) => picker.offsetParent !== null) || pickers[0] || null;
    };

    const countSelectedDays = (fieldName) => {
        const picker = getActiveSportPicker(fieldName);
        if (!picker) return 0;
        return Array.from(picker.querySelectorAll('[data-day-value]')).filter((btn) => btn.classList.contains('border-strava-orange')).length;
    };

    const requiresTrainingFocus = () => typeInput?.value === 'training' && disciplineInput?.value === 'triathlon';

    const validateCurrentStep = () => {
        if (current === 1) {
            if (!disciplineInput || !disciplineInput.value) {
                setWizardError('Pick a discipline before moving on.');
                return false;
            }

            if (typeInput && typeInput.value === 'race') {
                const raceProfileSelect = node.querySelector('[data-race-profile-section] [name="targetRaceProfile"]');
                if (raceProfileSelect && !raceProfileSelect.value) {
                    setWizardError('Choose a distance for the race plan.');
                    return false;
                }
            }

            if (requiresTrainingFocus() && trainingFocusSelect && !trainingFocusSelect.value) {
                setWizardError('Choose a training focus for a training block.');
                return false;
            }
        }

        if (current === 2 && disciplineInput) {
            if (disciplineInput.value === 'triathlon') {
                if (countSelectedDays('swimDays') === 0 || countSelectedDays('bikeDays') === 0 || countSelectedDays('runDays') === 0) {
                    setWizardError('Pick at least one swim, bike, and run day for a triathlon plan.');
                    return false;
                }
            }

            if (disciplineInput.value === 'running' && countSelectedDays('runDays') === 0) {
                setWizardError('Pick at least one running day before continuing.');
                return false;
            }

            if (disciplineInput.value === 'cycling' && countSelectedDays('bikeDays') === 0) {
                setWizardError('Pick at least one ride day before continuing.');
                return false;
            }
        }

        if (current === 4) {
            const startDayInput = node.querySelector('[data-start-day-input]');
            const endDayInput = node.querySelector('[data-end-day-input]');
            if (!startDayInput?.value || !endDayInput?.value) {
                setWizardError('Set both a start day and an end day for the plan window.');
                return false;
            }

            if (new Date(endDayInput.value) < new Date(startDayInput.value)) {
                setWizardError('The end day needs to be on or after the start day.');
                return false;
            }
        }

        clearWizardError();
        return true;
    };

    const show = (step) => {
        current = step;
        clearWizardError();
        steps.forEach((s) => {
            s.classList.toggle('hidden', parseInt(s.dataset.wizardStep, 10) !== current);
        });
        dots.forEach((d) => {
            const idx = parseInt(d.dataset.wizardDot, 10);
            d.classList.toggle('bg-strava-orange', idx <= current);
            d.classList.toggle('bg-gray-300', idx > current);
        });
        if (backBtn) {
            const showBack = current > 1;
            backBtn.classList.toggle('hidden', !showBack);
            backBtn.classList.toggle('inline-flex', showBack);
        }
        if (nextBtn) {
            const showNext = current < totalSteps;
            nextBtn.classList.toggle('hidden', !showNext);
            nextBtn.classList.toggle('inline-flex', showNext);
        }
        if (submitBtn) {
            const showSubmit = current === totalSteps;
            submitBtn.classList.toggle('hidden', !showSubmit);
            submitBtn.classList.toggle('inline-flex', showSubmit);
        }
    };

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (!validateCurrentStep()) return;
            if (current < totalSteps) show(current + 1);
        });
    }
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            if (current > 1) show(current - 1);
        });
    }

    // ── Discipline card selection (step 1). ──
    const disciplineCards = node.querySelectorAll('[data-discipline-cards] [data-discipline-value]');

    const applyDisciplineVisibility = (disc) => {
        // Step 2 — sport schedule sections.
        const triSection = node.querySelector('[data-sport-schedule-tri]');
        const runSection = node.querySelector('[data-sport-schedule-run]');
        const bikeSection = node.querySelector('[data-sport-schedule-bike]');
        if (triSection) triSection.classList.toggle('hidden', disc !== 'triathlon');
        if (runSection) runSection.classList.toggle('hidden', disc !== 'running');
        if (bikeSection) bikeSection.classList.toggle('hidden', disc !== 'cycling');

        // Step 3 — performance metric fields.
        const perfFtp = node.querySelector('[data-perf-ftp]');
        const perfPace = node.querySelector('[data-perf-pace]');
        const perfCss = node.querySelector('[data-perf-css]');
        const perfRunVol = node.querySelector('[data-perf-run-vol]');
        const runTargetModeSection = node.querySelector('[data-run-target-mode-section]');
        const runHillSection = node.querySelector('[data-run-hill-section]');
        const perfBikeVol = node.querySelector('[data-perf-bike-vol]');
        if (perfFtp) perfFtp.classList.toggle('hidden', !['triathlon', 'cycling'].includes(disc));
        if (perfPace) perfPace.classList.toggle('hidden', !['triathlon', 'running'].includes(disc));
        if (perfCss) perfCss.classList.toggle('hidden', disc !== 'triathlon');
        if (perfRunVol) perfRunVol.classList.toggle('hidden', !['triathlon', 'running'].includes(disc));
        if (runTargetModeSection) runTargetModeSection.classList.toggle('hidden', !['triathlon', 'running'].includes(disc));
        if (runHillSection) runHillSection.classList.toggle('hidden', !['triathlon', 'running'].includes(disc));
        if (perfBikeVol) perfBikeVol.classList.toggle('hidden', !['triathlon', 'cycling'].includes(disc));

        if (trainingFocusSection) {
            const showTrainingFocus = typeInput?.value === 'training' && disc === 'triathlon';
            trainingFocusSection.classList.toggle('hidden', !showTrainingFocus);
            if (!showTrainingFocus && trainingFocusSelect) {
                trainingFocusSelect.value = '';
            }
        }
    };

    if (disciplineCards.length && disciplineInput) {
        const selectDiscipline = (val) => {
            disciplineInput.value = val;
            disciplineInput.dispatchEvent(new Event('change', {bubbles: true}));
            disciplineCards.forEach((c) => {
                const isActive = c.dataset.disciplineValue === val;
                c.classList.toggle('border-strava-orange', isActive);
                c.classList.toggle('bg-orange-50', isActive);
                c.classList.toggle('dark:bg-orange-900/40', isActive);
                c.classList.toggle('ring-1', isActive);
                c.classList.toggle('ring-strava-orange/20', isActive);
                c.classList.toggle('border-gray-200', !isActive);
                c.classList.toggle('bg-white', !isActive);
                const icon = c.querySelector('div');
                if (icon) {
                    icon.classList.toggle('bg-strava-orange', isActive);
                    icon.classList.toggle('text-white', isActive);
                    icon.classList.toggle('bg-gray-100', !isActive);
                    icon.classList.toggle('text-gray-500', !isActive);
                }
            });
            applyDisciplineVisibility(val);
            clearWizardError();
        };
        disciplineCards.forEach((card) => {
            card.addEventListener('click', () => selectDiscipline(card.dataset.disciplineValue));
        });
        // Apply initial visibility if discipline already set.
        if (disciplineInput.value) applyDisciplineVisibility(disciplineInput.value);
    }

    // Type card selection (step 1).
    const typeCards = node.querySelectorAll('[data-plan-type-cards] [data-type-value]');
    const linkedRaceSection = node.querySelector('[data-linked-race-section]');
    const raceProfileSection = node.querySelector('[data-race-profile-section]');
    const trainingOnlySections = Array.from(node.querySelectorAll('[data-training-only-section]'));

    const applyTypeVisibility = (val) => {
        if (linkedRaceSection) linkedRaceSection.classList.toggle('hidden', val !== 'race');
        if (raceProfileSection) raceProfileSection.classList.remove('hidden');
        trainingOnlySections.forEach((section) => {
            section.classList.toggle('hidden', val !== 'training');
        });

        if (trainingFocusSection) {
            const showTrainingFocus = val === 'training' && disciplineInput?.value === 'triathlon';
            trainingFocusSection.classList.toggle('hidden', !showTrainingFocus);
            if (!showTrainingFocus && trainingFocusSelect) {
                trainingFocusSelect.value = '';
            }
        }
    };

    if (typeCards.length && typeInput) {
        typeCards.forEach((card) => {
            card.addEventListener('click', () => {
                const val = card.dataset.typeValue;
                typeInput.value = val;
                typeCards.forEach((c) => {
                    const isActive = c.dataset.typeValue === val;
                    c.classList.toggle('border-strava-orange', isActive);
                    c.classList.toggle('bg-orange-50', isActive);
                    c.classList.toggle('dark:bg-orange-900/40', isActive);
                    c.classList.toggle('ring-1', isActive);
                    c.classList.toggle('ring-strava-orange/20', isActive);
                    c.classList.toggle('border-gray-200', !isActive);
                    c.classList.toggle('bg-white', !isActive);
                    // Icon container
                    const icon = c.querySelector('div:first-child');
                    if (icon) {
                        icon.classList.toggle('bg-strava-orange', isActive);
                        icon.classList.toggle('text-white', isActive);
                        icon.classList.toggle('bg-gray-100', !isActive);
                        icon.classList.toggle('text-gray-500', !isActive);
                    }
                });
                applyTypeVisibility(val);
                clearWizardError();
            });
        });
        // Apply initial visibility.
        applyTypeVisibility(typeInput.value);
    }

    // ── Week-duration picker (step 4 plan details). ──
    const weekDurationPicker = node.querySelector('[data-week-duration-picker]');
    const startDayInput = node.querySelector('[data-start-day-input]');
    const endDayInput = node.querySelector('[data-end-day-input]');
    const durationLabel = node.querySelector('[data-duration-label]');

    const updateDurationLabel = () => {
        if (!durationLabel || !startDayInput || !endDayInput) return;
        const s = startDayInput.value;
        const e = endDayInput.value;
        if (!s || !e) { durationLabel.textContent = ''; return; }
        const start = new Date(s);
        const end = new Date(e);
        const days = Math.round((end - start) / 86400000) + 1;
        const weeks = Math.ceil(days / 7);
        durationLabel.textContent = weeks + ' week' + (weeks !== 1 ? 's' : '') + ' (' + days + ' days)';
    };

    const highlightWeekButton = () => {
        if (!weekDurationPicker || !startDayInput || !endDayInput) return;
        const s = startDayInput.value;
        const e = endDayInput.value;
        if (!s || !e) return;
        const days = Math.round((new Date(e) - new Date(s)) / 86400000) + 1;
        const weeks = Math.round(days / 7);
        weekDurationPicker.querySelectorAll('[data-weeks-value]').forEach((btn) => {
            const w = parseInt(btn.dataset.weeksValue, 10);
            const isActive = w === weeks;
            btn.classList.toggle('border-strava-orange', isActive);
            btn.classList.toggle('bg-strava-orange', isActive);
            btn.classList.toggle('text-white', isActive);
            btn.classList.toggle('scale-110', isActive);
            btn.classList.toggle('border-gray-200', !isActive);
            btn.classList.toggle('bg-white', !isActive);
            btn.classList.toggle('text-gray-600', !isActive);
        });
    };

    if (weekDurationPicker && startDayInput && endDayInput) {
        weekDurationPicker.querySelectorAll('[data-weeks-value]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const weeks = parseInt(btn.dataset.weeksValue, 10);
                const start = startDayInput.value ? new Date(startDayInput.value) : new Date();
                const end = new Date(start);
                end.setDate(end.getDate() + (weeks * 7) - 1);
                endDayInput.value = end.toISOString().slice(0, 10);
                highlightWeekButton();
                updateDurationLabel();
            });
        });
        startDayInput.addEventListener('change', () => {
            highlightWeekButton();
            updateDurationLabel();
        });
        endDayInput.addEventListener('change', () => {
            highlightWeekButton();
            updateDurationLabel();
        });
        // Set initial state.
        highlightWeekButton();
        updateDurationLabel();
    }

    if (form) {
        form.addEventListener('submit', (event) => {
            if (!validateCurrentStep()) {
                event.preventDefault();
            }
        });
    }

    show(1);
};

// ── Sessions-per-week & training-days pickers (shared between wizard and edit mode) ──
const initTrainingPlanPickers = (node) => {
    // ── Sport day pickers (step 3 — multiple [data-sport-day-picker] sections). ──
    node.querySelectorAll('[data-sport-day-picker]').forEach((container) => {
        const fieldName = container.dataset.sportDayPicker; // e.g. "swimDays"
        let hiddenBox = container.parentNode.querySelector(`[data-sport-hidden="${fieldName}"]`);
        if (!hiddenBox) {
            hiddenBox = document.createElement('div');
            hiddenBox.setAttribute('data-sport-hidden', fieldName);
            hiddenBox.style.display = 'none';
            container.parentNode.appendChild(hiddenBox);
        }
        const syncSportHidden = () => {
            hiddenBox.innerHTML = '';
            container.querySelectorAll('[data-day-value]').forEach((btn) => {
                if (btn.classList.contains('border-strava-orange')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `${fieldName}[]`;
                    input.value = btn.dataset.dayValue;
                    hiddenBox.appendChild(input);
                }
            });
        };
        container.querySelectorAll('[data-day-value]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const isActive = btn.classList.contains('border-strava-orange');
                const isCompact = btn.classList.contains('rounded-full');
                if (isCompact) {
                    btn.classList.toggle('border-strava-orange', !isActive);
                    btn.classList.toggle('bg-strava-orange', !isActive);
                    btn.classList.toggle('text-white', !isActive);
                    btn.classList.toggle('border-gray-300', isActive);
                    btn.classList.toggle('bg-white', isActive);
                    btn.classList.toggle('text-gray-500', isActive);
                } else {
                    btn.classList.toggle('border-strava-orange', !isActive);
                    btn.classList.toggle('bg-orange-50', !isActive);
                    btn.classList.toggle('dark:bg-orange-900/40', !isActive);
                    btn.classList.toggle('text-strava-orange', !isActive);
                    btn.classList.toggle('dark:text-orange-300', !isActive);
                    btn.classList.toggle('border-gray-200', isActive);
                    btn.classList.toggle('bg-white', isActive);
                    btn.classList.toggle('text-gray-500', isActive);
                }
                syncSportHidden();
            });
        });
        syncSportHidden();
    });

    // ── Pace inputs (mm:ss ↔ seconds). ──
    node.querySelectorAll('[data-pace-input]').forEach((displayInput) => {
        const hiddenInput = displayInput.parentNode.querySelector('[data-pace-seconds]');
        if (!hiddenInput) return;

        const parseToSeconds = (val) => {
            const m = val.match(/^(\d{1,2}):([0-5]\d)$/);
            return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
        };
        displayInput.addEventListener('input', () => {
            const secs = parseToSeconds(displayInput.value);
            hiddenInput.value = secs !== null ? secs : '';
        });
        displayInput.addEventListener('blur', () => {
            const secs = parseToSeconds(displayInput.value);
            if (secs !== null) {
                const m = Math.floor(secs / 60);
                const s = secs % 60;
                displayInput.value = `${m}:${s.toString().padStart(2, '0')}`;
            }
        });
    });

    // ── Edit mode: discipline dropdown toggles schedule + perf sections. ──
    const disciplineSelect = node.querySelector('[data-discipline-select]');
    if (disciplineSelect) {
        const applyEditDiscipline = (val) => {
            // Sport schedule sections.
            const schedTri = node.querySelector('[data-edit-sched-tri]');
            const schedRun = node.querySelector('[data-edit-sched-run]');
            const schedBike = node.querySelector('[data-edit-sched-bike]');
            if (schedTri) schedTri.classList.toggle('hidden', val !== 'triathlon');
            if (schedRun) schedRun.classList.toggle('hidden', val !== 'running');
            if (schedBike) schedBike.classList.toggle('hidden', val !== 'cycling');

            // Performance metric sections.
            const perfFtp = node.querySelector('[data-edit-perf-ftp]');
            const perfPace = node.querySelector('[data-edit-perf-pace]');
            const perfCss = node.querySelector('[data-edit-perf-css]');
            const perfRunVol = node.querySelector('[data-edit-perf-run-vol]');
            const runTargetMode = node.querySelector('[data-edit-run-target-mode]');
            const runHillSessions = node.querySelector('[data-edit-run-hill-sessions]');
            const perfBikeVol = node.querySelector('[data-edit-perf-bike-vol]');
            if (perfFtp) perfFtp.classList.toggle('hidden', !['triathlon', 'cycling'].includes(val));
            if (perfPace) perfPace.classList.toggle('hidden', !['triathlon', 'running'].includes(val));
            if (perfCss) perfCss.classList.toggle('hidden', val !== 'triathlon');
            if (perfRunVol) perfRunVol.classList.toggle('hidden', !['triathlon', 'running'].includes(val));
            if (runTargetMode) runTargetMode.classList.toggle('hidden', !['triathlon', 'running'].includes(val));
            if (runHillSessions) runHillSessions.classList.toggle('hidden', !['triathlon', 'running'].includes(val));
            if (perfBikeVol) perfBikeVol.classList.toggle('hidden', !['triathlon', 'cycling'].includes(val));
        };
        disciplineSelect.addEventListener('change', () => applyEditDiscipline(disciplineSelect.value));
        applyEditDiscipline(disciplineSelect.value);
    }

    const editTypeSelect = node.querySelector('[data-edit-type-select]');
    const editDisciplineSelect = node.querySelector('[data-discipline-select]');
    if (editTypeSelect) {
        const applyEditType = (val) => {
            node.querySelectorAll('[data-edit-linked-race]').forEach((field) => {
                field.classList.toggle('hidden', val !== 'race');
            });
            node.querySelectorAll('[data-edit-training-focus]').forEach((field) => {
                field.classList.toggle('hidden', val !== 'training');
            });
            node.querySelectorAll('[data-edit-training-focus-section]').forEach((field) => {
                const showTrainingFocus = val === 'training' && editDisciplineSelect?.value === 'triathlon';
                field.classList.toggle('hidden', !showTrainingFocus);
                if (!showTrainingFocus) {
                    const select = field.querySelector('select[name="trainingFocus"]');
                    if (select) {
                        select.value = '';
                    }
                }
            });
        };

        editTypeSelect.addEventListener('change', () => applyEditType(editTypeSelect.value));
        applyEditType(editTypeSelect.value);
    }

    if (editDisciplineSelect) {
        const applyEditTrainingFocusVisibility = () => {
            const showTrainingFocus = editTypeSelect?.value === 'training' && editDisciplineSelect.value === 'triathlon';
            node.querySelectorAll('[data-edit-training-focus-section]').forEach((field) => {
                field.classList.toggle('hidden', !showTrainingFocus);
                if (!showTrainingFocus) {
                    const select = field.querySelector('select[name="trainingFocus"]');
                    if (select) {
                        select.value = '';
                    }
                }
            });
        };

        editDisciplineSelect.addEventListener('change', applyEditTrainingFocusVisibility);
        applyEditTrainingFocusVisibility();
    }
};

eventBus.on(Events.MODAL_LOADED, async ({node, modalName}) => {
    initElements(node);

    // Race event family ↔ profile filtering.
    const familySelect = node.querySelector('select[name="family"]');
    const profileSelect = node.querySelector('select[name="profile"]');
    if (familySelect && profileSelect) {
        const syncProfiles = () => {
            const selectedFamily = familySelect.value;
            let hasVisibleSelected = false;
            Array.from(profileSelect.options).forEach((opt) => {
                const visible = opt.dataset.family === selectedFamily;
                opt.hidden = !visible;
                opt.disabled = !visible;
                if (visible && opt.selected) {
                    hasVisibleSelected = true;
                }
            });
            if (!hasVisibleSelected) {
                const first = Array.from(profileSelect.options).find((o) => !o.disabled);
                if (first) first.selected = true;
            }
        };
        familySelect.addEventListener('change', syncProfiles);
        syncProfiles();
    }

    // Training plan discipline ↔ profile filtering.
    const trainingPlanDisciplineInput = node.querySelector('[name="discipline"]');
    node.querySelectorAll('[data-race-profile-select]').forEach((profSel) => {
        const syncRaceProfiles = () => {
            const discipline = trainingPlanDisciplineInput?.value || '';
            let hasVisibleSelected = false;
            Array.from(profSel.options).forEach((opt) => {
                if (!opt.value) return; // keep placeholder
                const allowedDisciplines = (opt.dataset.disciplines || '').split(',').filter(Boolean);
                const visible = !discipline || allowedDisciplines.length === 0 || allowedDisciplines.includes(discipline);
                opt.hidden = !visible;
                opt.disabled = !visible;
                if (visible && opt.selected) hasVisibleSelected = true;
            });
            if (!hasVisibleSelected) {
                profSel.value = '';
            }
        };

        trainingPlanDisciplineInput?.addEventListener('change', syncRaceProfiles);
        syncRaceProfiles();
    });

    if (modalName === 'ai-chat') {
        const {default: Chat} = await import(
            /* webpackChunkName: "chat" */ './features/chat/chat'
            );
        new Chat(node).render();
    }

    // Training plan wizard + interactive pickers.
    initTrainingPlanWizard(node);
    initTrainingPlanPickers(node);
});
eventBus.on(Events.DATA_TABLE_CLUSTER_CHANGED, ({node}) => {
    modalManager.init(node);
});

const $modalAIChat = document.querySelector('a[data-modal-custom-ai]');
if ($modalAIChat) {
    $modalAIChat.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const modalId = $modalAIChat.getAttribute('data-modal-custom-ai');
        modalManager.open(modalId);
        router.pushCurrentRouteToHistoryState(modalId);
    });
}

(async () => {
    await updateGithubLatestRelease();
})();
