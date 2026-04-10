export default class WorkoutEditor {
    static STEP_TYPE_COLORS = {
        warmup: 'warmup',
        interval: 'interval',
        recovery: 'recovery',
        cooldown: 'cooldown',
    };

    init(rootNode) {
        this.initPlannerOutlookToggles(rootNode);

        rootNode.querySelectorAll('[data-workout-editor]').forEach((editorNode) => {
            if (editorNode.dataset.workoutEditorInitialized === 'true') {
                this.refreshEditor(editorNode);

                return;
            }

            editorNode.dataset.workoutEditorInitialized = 'true';
            editorNode.dataset.nextWorkoutItemId = String(Date.now());

            editorNode.addEventListener('pointerdown', (event) => {
                const handleNode = event.target.closest('[data-workout-drag-handle]');
                if (!handleNode) {
                    delete editorNode.dataset.dragHandleItemId;
                    editorNode._pointerDragContext = null;

                    return;
                }

                const itemNode = handleNode.closest('[data-workout-item]');
                if (!itemNode) {
                    delete editorNode.dataset.dragHandleItemId;
                    editorNode._pointerDragContext = null;

                    return;
                }

                editorNode.dataset.dragHandleItemId = itemNode.dataset.itemId ?? '';
                editorNode._pointerDragContext = {
                    pointerId: event.pointerId,
                    itemId: itemNode.dataset.itemId ?? '',
                    startX: event.clientX,
                    startY: event.clientY,
                    activated: false,
                    handleNode,
                };

                if (handleNode.setPointerCapture) {
                    handleNode.setPointerCapture(event.pointerId);
                }
            });

            editorNode.addEventListener('pointerup', () => {
                delete editorNode.dataset.dragHandleItemId;
            });

            editorNode.addEventListener('pointercancel', () => {
                delete editorNode.dataset.dragHandleItemId;
            });

            editorNode.addEventListener('pointermove', (event) => {
                const pointerDragContext = editorNode._pointerDragContext;
                if (!pointerDragContext || pointerDragContext.pointerId !== event.pointerId) {
                    return;
                }

                const draggedItem = this.getDraggedItemById(editorNode, pointerDragContext.itemId);
                if (!draggedItem) {
                    this.clearPointerDragState(editorNode);

                    return;
                }

                if (!pointerDragContext.activated) {
                    const dragDistance = Math.hypot(event.clientX - pointerDragContext.startX, event.clientY - pointerDragContext.startY);
                    if (dragDistance < 4) {
                        return;
                    }

                    pointerDragContext.activated = true;
                    editorNode.dataset.draggingItemId = pointerDragContext.itemId;
                    this.applyDraggingStyles(draggedItem);
                }

                event.preventDefault();
                this.autoScrollPointerDrag(editorNode, event.clientY);
                const dropzone = this.getDropzoneAtPoint(editorNode, event.clientX, event.clientY, draggedItem);
                if (!dropzone) {
                    this.clearDropIndicator(editorNode);

                    return;
                }

                const blockNode = dropzone.closest('[data-workout-block]');
                if (blockNode) {
                    this.setBlockCollapsed(blockNode, false);
                }

                this.showDropIndicator(editorNode, dropzone, this.getDropReferenceNode(dropzone, event.clientY, draggedItem));
            });

            editorNode.addEventListener('pointerup', (event) => {
                const pointerDragContext = editorNode._pointerDragContext;
                if (!pointerDragContext || pointerDragContext.pointerId !== event.pointerId) {
                    return;
                }

                if (pointerDragContext.activated) {
                    event.preventDefault();
                    const draggedItem = this.getDraggedItemById(editorNode, pointerDragContext.itemId);
                    const dropzone = draggedItem ? this.getDropzoneAtPoint(editorNode, event.clientX, event.clientY, draggedItem) : null;

                    if (draggedItem && dropzone && this.canDropIntoZone(draggedItem, dropzone)) {
                        const referenceNode = this.getDropReferenceNode(dropzone, event.clientY, draggedItem);
                        if (referenceNode) {
                            dropzone.insertBefore(draggedItem, referenceNode);
                        } else {
                            dropzone.appendChild(draggedItem);
                        }

                        this.refreshEditor(editorNode);
                    }
                }

                this.clearPointerDragState(editorNode);
            });

            editorNode.addEventListener('pointercancel', (event) => {
                const pointerDragContext = editorNode._pointerDragContext;
                if (!pointerDragContext || pointerDragContext.pointerId !== event.pointerId) {
                    return;
                }

                this.clearPointerDragState(editorNode);
            });

            editorNode.addEventListener('click', (event) => {
                const addStepButton = event.target.closest('[data-workout-step-add]');
                if (addStepButton) {
                    event.preventDefault();
                    this.appendStep(editorNode, this.getTopLevelDropzone(editorNode));

                    return;
                }

                const addBlockButton = event.target.closest('[data-workout-block-add]');
                if (addBlockButton) {
                    event.preventDefault();
                    this.appendBlock(editorNode);

                    return;
                }

                const addStepToBlockButton = event.target.closest('[data-workout-step-add-to-block]');
                if (addStepToBlockButton) {
                    event.preventDefault();
                    const blockNode = addStepToBlockButton.closest('[data-workout-block]');
                    const blockStepsNode = blockNode?.querySelector('[data-workout-block-steps]');
                    if (!blockNode || !blockStepsNode) {
                        return;
                    }

                    this.appendStep(editorNode, blockStepsNode, blockNode.dataset.itemId ?? '');

                    return;
                }

                const duplicateButton = event.target.closest('[data-workout-item-duplicate]');
                if (duplicateButton) {
                    event.preventDefault();
                    const itemNode = duplicateButton.closest('[data-workout-item]');
                    if (!itemNode) {
                        return;
                    }

                    this.duplicateItem(editorNode, itemNode);

                    return;
                }

                const toggleBlockButton = event.target.closest('[data-workout-block-toggle]');
                if (toggleBlockButton) {
                    event.preventDefault();
                    const blockNode = toggleBlockButton.closest('[data-workout-block]');
                    if (!blockNode) {
                        return;
                    }

                    this.toggleBlock(blockNode);

                    return;
                }

                const removeButton = event.target.closest('[data-workout-item-remove]');
                if (!removeButton) {
                    return;
                }

                event.preventDefault();
                const itemNode = removeButton.closest('[data-workout-item]');
                if (!itemNode) {
                    return;
                }

                this.removeItem(editorNode, itemNode);
            });

            editorNode.addEventListener('change', (event) => {
                const targetTypeSelect = event.target.closest('[data-workout-step-target-type]');
                if (targetTypeSelect) {
                    this.refreshTargetFields(targetTypeSelect.closest('[data-workout-step]'));
                    this.reindexEditor(editorNode);
                }

                const typeSelect = event.target.closest('[data-workout-step-type-select]');
                if (typeSelect) {
                    this.refreshStepTypeIndicator(typeSelect.closest('[data-workout-step]'));
                }

                const repsInput = event.target.closest('[data-workout-block-reps-input]');
                if (repsInput) {
                    this.refreshBlockRepsBadge(repsInput.closest('[data-workout-block]'));
                }

                this.refreshDependentSummaries(event.target, editorNode);
            });

            editorNode.addEventListener('input', (event) => {
                const repsInput = event.target.closest('[data-workout-block-reps-input]');
                if (repsInput) {
                    this.refreshBlockRepsBadge(repsInput.closest('[data-workout-block]'));
                }

                this.refreshDependentSummaries(event.target, editorNode);
            });

            editorNode.addEventListener('dragstart', (event) => {
                const handleNode = event.target.closest('[data-workout-drag-handle]');
                const itemNode = event.target.closest('[data-workout-item]');
                if (!itemNode && !handleNode) {
                    return;
                }

                const resolvedItemNode = itemNode ?? handleNode?.closest('[data-workout-item]');
                if (!resolvedItemNode) {
                    return;
                }

                const dragHandleItemId = editorNode.dataset.dragHandleItemId ?? '';
                if (dragHandleItemId !== (resolvedItemNode.dataset.itemId ?? '') && !handleNode) {
                    event.preventDefault();

                    return;
                }

                const dropzone = event.target.closest('[data-workout-dropzone]');
                if (resolvedItemNode.matches('[data-workout-block]') && dropzone?.dataset.dropzoneType === 'block') {
                    event.preventDefault();

                    return;
                }

                editorNode.dataset.draggingItemId = resolvedItemNode.dataset.itemId ?? '';
                this.applyDraggingStyles(resolvedItemNode);

                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', resolvedItemNode.dataset.itemId ?? '');
                }
            });

            editorNode.addEventListener('dragend', () => {
                this.clearDraggingState(editorNode);
            });

            editorNode.addEventListener('dragover', (event) => {
                const dropzone = event.target.closest('[data-workout-dropzone]');
                const draggedItem = this.getDraggedItem(editorNode);
                if (!dropzone || !draggedItem || !editorNode.contains(dropzone) || !this.canDropIntoZone(draggedItem, dropzone)) {
                    return;
                }

                event.preventDefault();
                const blockNode = dropzone.closest('[data-workout-block]');
                if (blockNode) {
                    this.setBlockCollapsed(blockNode, false);
                }
                this.showDropIndicator(editorNode, dropzone, this.getDropReferenceNode(dropzone, event.clientY, draggedItem));
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
            });

            editorNode.addEventListener('dragleave', (event) => {
                const relatedTarget = event.relatedTarget;
                if (relatedTarget instanceof Node && editorNode.contains(relatedTarget)) {
                    return;
                }

                this.clearDropIndicator(editorNode);
            });

            editorNode.addEventListener('drop', (event) => {
                const dropzone = event.target.closest('[data-workout-dropzone]');
                const draggedItem = this.getDraggedItem(editorNode);
                if (!dropzone || !draggedItem || !editorNode.contains(dropzone) || !this.canDropIntoZone(draggedItem, dropzone)) {
                    return;
                }

                event.preventDefault();
                const referenceNode = this.getDropReferenceNode(dropzone, event.clientY, draggedItem);
                this.clearDropIndicator(editorNode);

                if (referenceNode) {
                    dropzone.insertBefore(draggedItem, referenceNode);
                } else {
                    dropzone.appendChild(draggedItem);
                }

                this.refreshEditor(editorNode);
            });

            const formNode = editorNode.closest('form');
            this.initPlannerAutofill(editorNode, formNode);
            formNode?.addEventListener('submit', () => {
                this.reindexEditor(editorNode);
                this.refreshPlannerAutofill(editorNode);
            });

            this.refreshEditor(editorNode);
        });
    }

    appendStep(editorNode, targetContainer, parentBlockId = '') {
        const stepTemplate = this.getTemplateNode(editorNode, '[data-workout-step-template]');
        if (!stepTemplate || !targetContainer) {
            return;
        }

        const stepNode = this.createNodeFromTemplate(editorNode, stepTemplate);
        stepNode.dataset.parentBlockId = parentBlockId;
        targetContainer.appendChild(stepNode);
        this.refreshEditor(editorNode);
    }

    appendBlock(editorNode) {
        const blockTemplate = this.getTemplateNode(editorNode, '[data-workout-block-template]');
        const topLevelDropzone = this.getTopLevelDropzone(editorNode);
        if (!blockTemplate || !topLevelDropzone) {
            return;
        }

        topLevelDropzone.appendChild(this.createNodeFromTemplate(editorNode, blockTemplate));
        this.refreshEditor(editorNode);
    }

    removeItem(editorNode, itemNode) {
        if (itemNode.matches('[data-workout-block]')) {
            const blockStepsNode = itemNode.querySelector('[data-workout-block-steps]');
            if (blockStepsNode) {
                Array.from(blockStepsNode.children).forEach((childNode) => {
                    itemNode.after(childNode);
                });
            }
        }

        itemNode.remove();
        this.refreshEditor(editorNode);
    }

    duplicateItem(editorNode, itemNode) {
        const cloneNode = itemNode.cloneNode(true);
        this.assignFreshItemIds(editorNode, cloneNode);
        itemNode.after(cloneNode);
        this.refreshEditor(editorNode);
    }

    refreshEditor(editorNode) {
        const topLevelDropzone = this.getTopLevelDropzone(editorNode);
        if (!topLevelDropzone) {
            return;
        }

        this.ensureTemplatesInsideEditor(editorNode, topLevelDropzone);

        if (!topLevelDropzone.querySelector(':scope > [data-workout-item]')) {
            this.appendStep(editorNode, topLevelDropzone);

            return;
        }

        this.syncParentBlockIds(editorNode);
        editorNode.querySelectorAll('[data-workout-item], [data-workout-drag-handle]').forEach((node) => {
            node.draggable = false;
        });
        editorNode.querySelectorAll('[data-workout-step]').forEach((stepNode) => this.refreshTargetFields(stepNode));
        editorNode.querySelectorAll('[data-workout-block]').forEach((blockNode) => {
            this.refreshBlockEmptyState(blockNode);
            this.refreshBlockSummary(blockNode);
            this.refreshBlockRepsBadge(blockNode);
        });
        this.reindexEditor(editorNode);
        this.refreshPlannerAutofill(editorNode);
    }

    refreshTargetFields(stepNode) {
        if (!stepNode) {
            return;
        }

        const targetType = stepNode.querySelector('[data-workout-step-target-type]')?.value ?? 'time';
        const activityType = this.getSelectedActivityType(stepNode);
        stepNode.querySelectorAll('[data-target-field-group]').forEach((groupNode) => {
            const visibleFor = (groupNode.dataset.targetFieldGroup ?? '').split(/\s+/).filter(Boolean);
            let isVisible = visibleFor.includes(targetType);
            const targetEffort = groupNode.dataset.targetEffort ?? '';
            if (isVisible && targetEffort) {
                isVisible = this.isRideActivityType(activityType)
                    ? targetEffort === 'power'
                    : targetEffort === 'pace';
            }

            groupNode.classList.toggle('hidden', !isVisible);

            groupNode.querySelectorAll('input, select, textarea').forEach((fieldNode) => {
                fieldNode.disabled = !isVisible;
            });
        });

        this.refreshConditionOptions(stepNode, targetType);

        this.refreshStepTypeIndicator(stepNode);
    }

    refreshStepTypeIndicator(stepNode) {
        if (!stepNode) {
            return;
        }

        const typeSelect = stepNode.querySelector('[data-workout-step-type-select]');
        const innerNode = stepNode.querySelector('.planned-session-step-inner');
        if (!typeSelect || !innerNode) {
            return;
        }

        const stepType = typeSelect.value;
        const color = WorkoutEditor.STEP_TYPE_COLORS[stepType] ?? null;

        Object.values(WorkoutEditor.STEP_TYPE_COLORS).forEach((colorValue) => {
            innerNode.removeAttribute('data-step-color');
        });

        if (color) {
            innerNode.dataset.stepColor = color;
        } else {
            delete innerNode.dataset.stepColor;
        }

        stepNode.dataset.stepType = stepType;
    }

    refreshBlockRepsBadge(blockNode) {
        const repsInput = blockNode.querySelector('[data-workout-block-reps-input]');
        const repsBadge = blockNode.querySelector('[data-workout-block-reps-badge]');
        if (!repsInput || !repsBadge) {
            return;
        }

        const reps = parseInt(repsInput.value ?? '1', 10) || 1;
        repsBadge.textContent = `${reps}×`;
    }

    initPlannerAutofill(editorNode, formNode) {
        if (!formNode || formNode.dataset.plannedSessionAutofillInitialized === 'true') {
            return;
        }

        formNode.dataset.plannedSessionAutofillInitialized = 'true';

        const refresh = () => this.refreshPlannerAutofill(editorNode);
        const loadInput = this.getPlannerField(formNode, '[data-planned-session-target-load]');
        if (loadInput) {
            const updateManualState = () => {
                formNode.dataset.plannedSessionManualTargetLoad = loadInput.value.trim() === '' ? 'false' : 'true';
                refresh();
            };

            loadInput.addEventListener('input', updateManualState);
            loadInput.addEventListener('change', updateManualState);
        }

        const activityTypeField = this.getPlannerField(formNode, '[data-planned-session-activity-type]');

        [
            this.getPlannerField(formNode, '[data-planned-session-intensity]'),
            this.getPlannerField(formNode, '[data-planned-session-template-activity]'),
            this.getPlannerField(formNode, '[data-planned-session-target-duration-minutes]'),
            this.getPlannerField(formNode, '[data-planned-session-target-duration-seconds]'),
        ].filter(Boolean).forEach((fieldNode) => {
            fieldNode.addEventListener('change', refresh);
            if (fieldNode.matches('input')) {
                fieldNode.addEventListener('input', refresh);
            }
        });

        if (activityTypeField) {
            activityTypeField.addEventListener('change', () => {
                editorNode.querySelectorAll('[data-workout-step]').forEach((stepNode) => this.refreshTargetFields(stepNode));
                editorNode.querySelectorAll('[data-workout-block]').forEach((blockNode) => this.refreshBlockSummary(blockNode));
                refresh();
            });
        }
    }

    refreshPlannerAutofill(editorNode) {
        const formNode = editorNode.closest('[data-planned-session-form]');
        if (!formNode) {
            return;
        }

        const workoutItems = this.collectWorkoutItems(this.getTopLevelDropzone(editorNode));
        const hasConfiguredWorkout = workoutItems.length > 0;
        const derivedDurationInSeconds = hasConfiguredWorkout
            ? this.calculateWorkoutSequenceDuration(workoutItems)
            : null;

        const durationMinutesInput = this.getPlannerField(formNode, '[data-planned-session-target-duration-minutes]');
        const durationSecondsInput = this.getPlannerField(formNode, '[data-planned-session-target-duration-seconds]');
        if (derivedDurationInSeconds !== null && durationMinutesInput && durationSecondsInput) {
            this.applyDurationInputs(durationMinutesInput, durationSecondsInput, derivedDurationInSeconds);
        }

        const effectiveDurationInSeconds = derivedDurationInSeconds ?? this.parseDurationInputValues(
            durationMinutesInput?.value,
            durationSecondsInput?.value,
        );
        const loadInput = this.getPlannerField(formNode, '[data-planned-session-target-load]');
        const manualLoadOverride = formNode.dataset.plannedSessionManualTargetLoad === 'true';

        let estimate = manualLoadOverride
            ? {
                estimatedLoad: this.parseNullableFloat(loadInput?.value),
                sourceLabel: this.getPlannerEstimationContext(formNode).labels?.manualTargetLoad ?? 'Manual target load',
            }
            : this.estimatePlannerLoad(formNode, effectiveDurationInSeconds);

        if (!estimate?.estimatedLoad && estimate?.estimatedLoad !== 0) {
            estimate = null;
        }

        if (!manualLoadOverride && loadInput) {
            loadInput.value = estimate?.estimatedLoad !== undefined && estimate?.estimatedLoad !== null
                ? this.formatLoadValue(estimate.estimatedLoad)
                : '';
        }

        this.updatePlannerEstimateDisplay(formNode, estimate, {
            manualLoadOverride,
            derivedDurationInSeconds,
            hasConfiguredWorkout,
        });
    }

    refreshConditionOptions(stepNode, targetType) {
        const conditionSelect = stepNode.querySelector('[data-workout-step-condition-select]');
        if (!conditionSelect) {
            return;
        }

        const defaultOption = conditionSelect.querySelector('[data-condition-default-option]');
        if (defaultOption) {
            defaultOption.textContent = targetType === 'time'
                ? (defaultOption.dataset.timeLabel ?? 'Target time')
                : (defaultOption.dataset.heartRateLabel ?? 'Hold target');
        }

        let hasVisibleSelection = false;
        Array.from(conditionSelect.options).forEach((optionNode) => {
            const validFor = (optionNode.dataset.validFor ?? '').split(/\s+/).filter(Boolean);
            const isVisible = validFor.includes(targetType);

            optionNode.hidden = !isVisible;
            optionNode.disabled = !isVisible;
            if (optionNode.selected && isVisible) {
                hasVisibleSelection = true;
            }
        });

        if (targetType === 'time') {
            if (!hasVisibleSelection || conditionSelect.value === 'holdTarget') {
                conditionSelect.value = '';
            }

            return;
        }

        if (targetType === 'heartRate') {
            if (!hasVisibleSelection || conditionSelect.value === '') {
                conditionSelect.value = 'holdTarget';
            }

            return;
        }

        conditionSelect.value = '';
    }

    getPlannerEstimationContext(formNode) {
        if (formNode._plannedSessionEstimationContext) {
            return formNode._plannedSessionEstimationContext;
        }

        try {
            formNode._plannedSessionEstimationContext = JSON.parse(formNode.dataset.plannedSessionEstimationContext ?? '{}');
        } catch {
            formNode._plannedSessionEstimationContext = {};
        }

        return formNode._plannedSessionEstimationContext;
    }

    getPlannerField(formNode, selector) {
        if (!formNode?.id) {
            return null;
        }

        return document.querySelector(`${selector}[form="${formNode.id}"]`) ?? formNode.querySelector(selector);
    }

    estimatePlannerLoad(formNode, effectiveDurationInSeconds) {
        const context = this.getPlannerEstimationContext(formNode);
        const templateSelect = this.getPlannerField(formNode, '[data-planned-session-template-activity]');
        const selectedTemplateOption = templateSelect?.selectedOptions?.[0] ?? null;
        const templateLoad = this.parseNullableFloat(selectedTemplateOption?.dataset.estimatedLoad);
        const templateMovingTimeInSeconds = this.parseNullableInteger(selectedTemplateOption?.dataset.movingTimeSeconds);

        if (templateSelect?.value && templateLoad !== null) {
            let estimatedLoad = templateLoad;

            if (effectiveDurationInSeconds !== null && templateMovingTimeInSeconds && templateMovingTimeInSeconds > 0) {
                estimatedLoad *= effectiveDurationInSeconds / templateMovingTimeInSeconds;
            }

            return {
                estimatedLoad: this.roundToSingleDecimal(estimatedLoad),
                sourceLabel: context.labels?.template ?? 'Template activity',
            };
        }

        if (effectiveDurationInSeconds === null || effectiveDurationInSeconds <= 0) {
            return null;
        }

        const intensityValue = this.getPlannerField(formNode, '[data-planned-session-intensity]')?.value ?? '';
        if (!intensityValue) {
            return null;
        }

        const activityTypeValue = this.getPlannerField(formNode, '[data-planned-session-activity-type]')?.value ?? '';
        const loadPerHour = context.loadPerHourByActivityType?.[activityTypeValue] ?? context.globalLoadPerHour ?? null;
        const intensityMultiplier = context.intensityMultipliers?.[intensityValue] ?? null;
        if (!Number.isFinite(loadPerHour) || !Number.isFinite(intensityMultiplier)) {
            return null;
        }

        return {
            estimatedLoad: this.roundToSingleDecimal((effectiveDurationInSeconds / 3600) * loadPerHour * intensityMultiplier),
            sourceLabel: context.labels?.durationIntensity ?? 'Duration and intensity estimate',
        };
    }

    updatePlannerEstimateDisplay(formNode, estimate, state) {
        const context = this.getPlannerEstimationContext(formNode);
        const badgeNode = document.querySelector(`[data-planned-session-estimate-badge][data-planned-session-form-id="${formNode.id}"]`);
        const noteNode = document.querySelector(`[data-planned-session-estimate-note][data-planned-session-form-id="${formNode.id}"]`);

        if (badgeNode) {
            if (estimate?.estimatedLoad !== undefined && estimate?.estimatedLoad !== null) {
                badgeNode.textContent = `${context.labels?.estimatedPrefix ?? 'Est.'} ${this.formatLoadValue(estimate.estimatedLoad)} · ${estimate.sourceLabel}`;
                badgeNode.classList.remove('hidden');
            } else {
                badgeNode.classList.add('hidden');
                badgeNode.textContent = '';
            }
        }

        if (!noteNode) {
            return;
        }

        let note = '';
        if (state.manualLoadOverride) {
            note = context.labels?.manualOverride ?? 'Manual load override';
        } else if (state.derivedDurationInSeconds !== null) {
            note = context.labels?.durationDerived ?? 'Duration derived from workout steps.';
        } else if (state.hasConfiguredWorkout) {
            note = context.labels?.durationPending ?? 'Duration stays manual until every set has a timed or paced target.';
        }

        noteNode.textContent = note;
        noteNode.classList.toggle('hidden', note === '');
    }

    collectWorkoutItems(containerNode, parentBlockId = null) {
        if (!containerNode) {
            return [];
        }

        const workoutItems = [];
        Array.from(containerNode.children)
            .filter((childNode) => childNode.matches('[data-workout-item]'))
            .forEach((itemNode) => {
                const itemId = itemNode.dataset.itemId ?? '';
                if (!itemId) {
                    return;
                }

                if (itemNode.matches('[data-workout-block]')) {
                    workoutItems.push({
                        itemId,
                        parentBlockId,
                        type: 'repeatBlock',
                        repetitions: this.parsePositiveInteger(itemNode.querySelector('[data-workout-block-reps-input]')?.value, 1),
                    });

                    workoutItems.push(...this.collectWorkoutItems(itemNode.querySelector('[data-workout-block-steps]'), itemId));

                    return;
                }

                const workoutStep = this.parseWorkoutStepNode(itemNode, parentBlockId);
                if (workoutStep) {
                    workoutItems.push(workoutStep);
                }
            });

        return workoutItems;
    }

    parseWorkoutStepNode(stepNode, parentBlockId) {
        const durationInSeconds = this.parseDurationInputValues(
            stepNode.querySelector('input[name$="[durationInMinutes]"]')?.value,
            stepNode.querySelector('input[name$="[durationInSecondsPart]"]')?.value,
        );
        const distanceInMeters = this.parseNullableInteger(stepNode.querySelector('input[name$="[distanceInMeters]"]')?.value);
        const targetHeartRate = this.parseNullableInteger(stepNode.querySelector('input[name$="[targetHeartRate]"]')?.value);
        let targetType = stepNode.querySelector('[data-workout-step-target-type]')?.value || null;
        targetType ??= this.inferWorkoutTargetType(durationInSeconds, distanceInMeters, targetHeartRate);

        let conditionType = stepNode.querySelector('[data-workout-step-condition-select]')?.value || null;
        conditionType ??= this.inferWorkoutConditionType(targetType);

        if (!this.isValidWorkoutTarget(targetType, conditionType, durationInSeconds, distanceInMeters, targetHeartRate)) {
            return null;
        }

        return {
            itemId: stepNode.dataset.itemId ?? '',
            parentBlockId,
            type: stepNode.querySelector('[data-workout-step-type-select]')?.value ?? 'interval',
            repetitions: this.parsePositiveInteger(stepNode.querySelector('input[name$="[repetitions]"]')?.value, 1),
            targetType,
            conditionType,
            durationInSeconds,
            distanceInMeters,
            targetPace: stepNode.querySelector('input[name$="[targetPace]"]')?.value?.trim() || null,
            targetPower: this.parseNullableInteger(stepNode.querySelector('input[name$="[targetPower]"]')?.value),
            targetHeartRate,
            recoveryAfterInSeconds: null,
        };
    }

    calculateWorkoutSequenceDuration(workoutItems, parentBlockId = null) {
        let totalDurationInSeconds = 0;

        for (const workoutItem of workoutItems) {
            if ((workoutItem.parentBlockId ?? null) !== parentBlockId) {
                continue;
            }

            if (workoutItem.type === 'repeatBlock') {
                const childDurationInSeconds = this.calculateWorkoutSequenceDuration(workoutItems, workoutItem.itemId);
                if (childDurationInSeconds === null) {
                    return null;
                }

                totalDurationInSeconds += Math.max(1, workoutItem.repetitions ?? 1) * childDurationInSeconds;

                continue;
            }

            const estimatedStepDurationInSeconds = this.estimateWorkoutStepDurationInSeconds(workoutItem);
            if (estimatedStepDurationInSeconds === null) {
                return null;
            }

            totalDurationInSeconds += (Math.max(1, workoutItem.repetitions ?? 1) * estimatedStepDurationInSeconds)
                + (Math.max(0, (workoutItem.repetitions ?? 1) - 1) * (workoutItem.recoveryAfterInSeconds ?? 0));
        }

        return totalDurationInSeconds;
    }

    estimateWorkoutStepDurationInSeconds(workoutStep) {
        if (workoutStep.targetType === 'heartRate') {
            return workoutStep.durationInSeconds ?? null;
        }

        if (workoutStep.durationInSeconds !== null && workoutStep.durationInSeconds > 0) {
            return workoutStep.durationInSeconds;
        }

        if (workoutStep.distanceInMeters === null || workoutStep.distanceInMeters <= 0) {
            return null;
        }

        const secondsPerMeter = this.parsePaceSecondsPerMeter(workoutStep.targetPace);
        if (secondsPerMeter === null) {
            return null;
        }

        return Math.round(secondsPerMeter * workoutStep.distanceInMeters);
    }

    inferWorkoutTargetType(durationInSeconds, distanceInMeters, targetHeartRate) {
        if (targetHeartRate !== null) {
            return 'heartRate';
        }

        if (distanceInMeters !== null) {
            return 'distance';
        }

        if (durationInSeconds !== null) {
            return 'time';
        }

        return null;
    }

    inferWorkoutConditionType(targetType) {
        return targetType === 'heartRate' ? 'holdTarget' : null;
    }

    isValidWorkoutTarget(targetType, conditionType, durationInSeconds, distanceInMeters, targetHeartRate) {
        if (targetType === 'time') {
            return ['holdTarget', 'lapButton', null, ''].includes(conditionType)
                && durationInSeconds !== null
                && durationInSeconds > 0;
        }

        if (targetType === 'distance') {
            return distanceInMeters !== null && distanceInMeters > 0;
        }

        if (targetType === 'heartRate') {
            switch (conditionType ?? 'holdTarget') {
            case 'holdTarget':
                return durationInSeconds !== null && durationInSeconds > 0 && targetHeartRate !== null && targetHeartRate > 0;
            case 'untilBelow':
            case 'untilAbove':
                return targetHeartRate !== null && targetHeartRate > 0;
            case 'lapButton':
                return true;
            default:
                return false;
            }
        }

        return false;
    }

    applyDurationInputs(minutesInput, secondsInput, totalDurationInSeconds) {
        const minutes = Math.floor(totalDurationInSeconds / 60);
        const seconds = totalDurationInSeconds % 60;

        minutesInput.value = String(Math.max(0, minutes));
        secondsInput.value = String(Math.max(0, seconds));
    }

    parseDurationInputValues(durationInMinutes, durationInSecondsPart) {
        const minutes = this.parseNullableInteger(durationInMinutes);
        const seconds = this.parseNullableInteger(durationInSecondsPart);

        if (minutes === null && seconds === null) {
            return null;
        }

        return ((minutes ?? 0) * 60) + (seconds ?? 0);
    }

    parseNullableInteger(value) {
        if (value === undefined || value === null || String(value).trim() === '') {
            return null;
        }

        const parsedValue = parseInt(String(value), 10);

        return Number.isFinite(parsedValue) ? Math.max(0, parsedValue) : null;
    }

    parsePositiveInteger(value, fallbackValue = 1) {
        const parsedValue = this.parseNullableInteger(value);

        return parsedValue === null || parsedValue <= 0 ? fallbackValue : parsedValue;
    }

    parseNullableFloat(value) {
        if (value === undefined || value === null || String(value).trim() === '') {
            return null;
        }

        const parsedValue = Number.parseFloat(String(value));

        return Number.isFinite(parsedValue) ? parsedValue : null;
    }

    parsePaceSecondsPerMeter(targetPace) {
        if (!targetPace) {
            return null;
        }

        const matches = String(targetPace).trim().match(/^(\d+):(\d{2})(?:\s*\/\s*(km|mi))?$/i);
        if (!matches) {
            return null;
        }

        const seconds = (parseInt(matches[1], 10) * 60) + parseInt(matches[2], 10);
        const unit = matches[3]?.toLowerCase() ?? 'km';
        const meters = unit === 'mi' ? 1609.344 : 1000;

        return seconds / meters;
    }

    roundToSingleDecimal(value) {
        return Math.round(value * 10) / 10;
    }

    formatLoadValue(value) {
        return this.roundToSingleDecimal(value).toFixed(1);
    }

    initPlannerOutlookToggles(rootNode) {
        rootNode.querySelectorAll('[data-planner-outlook-toggle]').forEach((toggleNode) => {
            if (toggleNode.dataset.plannerOutlookInitialized === 'true') {
                return;
            }

            toggleNode.dataset.plannerOutlookInitialized = 'true';
            const contentNode = toggleNode.parentElement?.querySelector('[data-planner-outlook-content]');
            if (!contentNode) {
                return;
            }

            toggleNode.setAttribute('aria-expanded', 'false');

            toggleNode.addEventListener('click', () => {
                const isExpanded = toggleNode.getAttribute('aria-expanded') === 'true';
                toggleNode.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                contentNode.classList.toggle('hidden', isExpanded);
            });
        });
    }

    refreshBlockEmptyState(blockNode) {
        const blockStepsNode = blockNode.querySelector('[data-workout-block-steps]');
        const emptyStateNode = blockNode.querySelector('[data-workout-block-empty]');
        if (!blockStepsNode || !emptyStateNode) {
            return;
        }

        emptyStateNode.classList.toggle('hidden', blockStepsNode.querySelector(':scope > [data-workout-item]') !== null);
    }

    refreshBlockSummary(blockNode) {
        const summaryNode = blockNode.querySelector('[data-workout-block-summary]');
        const toggleButton = blockNode.querySelector('[data-workout-block-toggle]');
        const blockStepsNode = blockNode.querySelector('[data-workout-block-steps]');
        if (!summaryNode || !toggleButton || !blockStepsNode) {
            return;
        }

        const repeats = parseInt(blockNode.querySelector('input[name$="[repetitions]"]')?.value ?? '1', 10) || 1;
        const setNodes = Array.from(blockStepsNode.querySelectorAll(':scope > [data-workout-item]'));
        const setCount = setNodes.length;
        const setLabel = setCount === 1
            ? `1 ${blockNode.dataset.setLabelSingular ?? 'set'}`
            : `${setCount} ${blockNode.dataset.setLabelPlural ?? 'sets'}`;
        const itemSummaries = setNodes
            .map((itemNode) => this.describeWorkoutItem(itemNode))
            .filter(Boolean);
        const detailSummary = itemSummaries.length === 0
            ? setLabel
            : `${itemSummaries.slice(0, 2).join(' + ')}${itemSummaries.length > 2 ? ` + ${itemSummaries.length - 2} ${blockNode.dataset.moreLabel ?? 'more'}` : ''}`;
        const isCollapsed = blockNode.dataset.collapsed === 'true';
        const toggleLabel = isCollapsed
            ? (blockNode.dataset.expandLabel ?? 'Expand block')
            : (blockNode.dataset.collapseLabel ?? 'Collapse block');

        summaryNode.textContent = `${repeats}× (${detailSummary})`;
        summaryNode.classList.toggle('hidden', !isCollapsed);
        toggleButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        toggleButton.setAttribute('title', toggleLabel);
        toggleButton.setAttribute('aria-label', toggleLabel);
        const toggleLabelNode = toggleButton.querySelector('[data-workout-block-toggle-label]');
        if (toggleLabelNode) {
            toggleLabelNode.textContent = toggleLabel;
        }
    }

    describeWorkoutItem(itemNode) {
        if (!itemNode?.matches('[data-workout-step]')) {
            return '';
        }

        const label = itemNode.querySelector('input[name$="[label]"]')?.value?.trim();
        const typeLabel = itemNode.querySelector('select[name$="[type]"] option:checked')?.textContent?.trim() ?? 'Set';
        const targetSummary = this.describeWorkoutTarget(itemNode);

        return [label || typeLabel, targetSummary].filter(Boolean).join(' ');
    }

    describeWorkoutTarget(stepNode) {
        const activityType = this.getSelectedActivityType(stepNode);
        const targetType = stepNode.querySelector('[data-workout-step-target-type]')?.value ?? 'time';
        const conditionType = stepNode.querySelector('[data-workout-step-condition-select]')?.value ?? '';
        const targetPace = stepNode.querySelector('input[name$="[targetPace]"]')?.value?.trim();
        const targetPower = stepNode.querySelector('input[name$="[targetPower]"]')?.value?.trim();
        const durationInMinutes = stepNode.querySelector('input[name$="[durationInMinutes]"]')?.value?.trim();
        const durationInSecondsPart = stepNode.querySelector('input[name$="[durationInSecondsPart]"]')?.value?.trim();
        const distanceInMeters = stepNode.querySelector('input[name$="[distanceInMeters]"]')?.value?.trim();
        const targetHeartRate = stepNode.querySelector('input[name$="[targetHeartRate]"]')?.value?.trim();
        const conditionLabel = stepNode.querySelector('select[name$="[conditionType]"] option:checked')?.textContent?.trim();
        const durationLabel = this.formatDurationLabel(durationInMinutes, durationInSecondsPart);
        const effortTarget = this.formatEffortTarget(activityType, targetPace, targetPower);

        if (targetType === 'distance' && distanceInMeters) {
            return `${distanceInMeters}m${effortTarget ? ` @ ${effortTarget}` : ''}`;
        }

        if (targetType === 'heartRate') {
            if (conditionType === 'lapButton') {
                if (durationLabel && targetHeartRate) {
                    return `${durationLabel} @ ${targetHeartRate} bpm or until button press`;
                }

                return targetHeartRate
                    ? `Until button press @ ${targetHeartRate} bpm`
                    : 'Until button press';
            }

            if (durationLabel && targetHeartRate) {
                return `${durationLabel} @ ${targetHeartRate} bpm`;
            }

            if (targetHeartRate) {
                return `${conditionLabel ?? 'HR'} ${targetHeartRate} bpm`;
            }

            return conditionLabel ?? '';
        }

        if (targetType === 'time') {
            if (!durationLabel) {
                return conditionType === 'lapButton'
                    ? 'Until button press'
                    : effortTarget ?? '';
            }

            const durationSummary = `${durationLabel}${effortTarget ? ` @ ${effortTarget}` : ''}`;

            return conditionType === 'lapButton'
                ? `${durationSummary} or until button press`
                : durationSummary;
        }

        if (durationLabel) {
            return `${durationLabel}${effortTarget ? ` @ ${effortTarget}` : ''}`;
        }

        return effortTarget ?? '';
    }

    getSelectedActivityType(node) {
        const formNode = node?.closest('[data-planned-session-form]');

        return this.getPlannerField(formNode, '[data-planned-session-activity-type]')?.value ?? 'Run';
    }

    isRideActivityType(activityType) {
        return activityType === 'Ride';
    }

    formatEffortTarget(activityType, targetPace, targetPower) {
        if (this.isRideActivityType(activityType)) {
            if (targetPower !== undefined && targetPower !== null && String(targetPower).trim() !== '') {
                return `${String(targetPower).trim()} W`;
            }

            return targetPace || null;
        }

        if (targetPace) {
            return targetPace;
        }

        if (targetPower !== undefined && targetPower !== null && String(targetPower).trim() !== '') {
            return `${String(targetPower).trim()} W`;
        }

        return null;
    }

    formatDurationLabel(durationInMinutes, durationInSecondsPart) {
        const minutes = durationInMinutes === '' || durationInMinutes === undefined ? null : parseInt(durationInMinutes, 10);
        const seconds = durationInSecondsPart === '' || durationInSecondsPart === undefined ? null : parseInt(durationInSecondsPart, 10);

        if ((!Number.isInteger(minutes) || minutes === null) && (!Number.isInteger(seconds) || seconds === null)) {
            return '';
        }

        const safeMinutes = Number.isInteger(minutes) ? Math.max(0, minutes) : 0;
        const safeSeconds = Number.isInteger(seconds) ? Math.max(0, seconds) : 0;
        if (safeMinutes === 0 && safeSeconds === 0) {
            return '';
        }

        if (safeMinutes > 0 && safeSeconds > 0) {
            return `${safeMinutes}m ${safeSeconds}s`;
        }

        if (safeMinutes > 0) {
            return `${safeMinutes} min`;
        }

        return `${safeSeconds}s`;
    }

    toggleBlock(blockNode) {
        this.setBlockCollapsed(blockNode, blockNode.dataset.collapsed !== 'true');
    }

    setBlockCollapsed(blockNode, isCollapsed) {
        const contentNode = blockNode.querySelector('[data-workout-block-content]');
        if (!contentNode) {
            return;
        }

        blockNode.dataset.collapsed = isCollapsed ? 'true' : 'false';
        contentNode.classList.toggle('hidden', isCollapsed);
        this.refreshBlockSummary(blockNode);
    }

    reindexEditor(editorNode) {
        let index = 0;
        const walk = (containerNode, parentBlockId = '') => {
            if (!containerNode) {
                return;
            }

            Array.from(containerNode.children)
                .filter((childNode) => childNode.matches('[data-workout-item]'))
                .forEach((itemNode) => {
                    itemNode.dataset.parentBlockId = parentBlockId;

                    itemNode.querySelectorAll('[data-name-template]').forEach((fieldNode) => {
                        const template = fieldNode.dataset.nameTemplate;
                        if (!template) {
                            return;
                        }

                        fieldNode.name = template.replaceAll('__INDEX__', String(index));
                    });

                    const parentBlockInput = itemNode.querySelector('[data-parent-block-id-input]');
                    if (parentBlockInput) {
                        parentBlockInput.value = parentBlockId;
                    }

                    index += 1;

                    if (itemNode.matches('[data-workout-block]')) {
                        walk(itemNode.querySelector('[data-workout-block-steps]'), itemNode.dataset.itemId ?? '');
                    }
                });
        };

        walk(this.getTopLevelDropzone(editorNode));
    }

    syncParentBlockIds(editorNode) {
        const topLevelDropzone = this.getTopLevelDropzone(editorNode);
        Array.from(topLevelDropzone.children)
            .filter((childNode) => childNode.matches('[data-workout-item]'))
            .forEach((itemNode) => {
                itemNode.dataset.parentBlockId = '';
            });

        editorNode.querySelectorAll('[data-workout-block]').forEach((blockNode) => {
            const blockId = blockNode.dataset.itemId ?? '';
            const blockStepsNode = blockNode.querySelector('[data-workout-block-steps]');
            if (!blockStepsNode) {
                return;
            }

            Array.from(blockStepsNode.children)
                .filter((childNode) => childNode.matches('[data-workout-item]'))
                .forEach((itemNode) => {
                    itemNode.dataset.parentBlockId = blockId;
                });
        });
    }

    canDropIntoZone(draggedItem, dropzone) {
        if (!draggedItem || !dropzone) {
            return false;
        }

        if (draggedItem === dropzone || draggedItem.contains(dropzone)) {
            return false;
        }

        if (dropzone.dataset.dropzoneType === 'block' && draggedItem.matches('[data-workout-block]')) {
            return false;
        }

        return true;
    }

    getDropReferenceNode(dropzone, clientY, draggedItem) {
        return Array.from(dropzone.children)
            .filter((childNode) => childNode.matches('[data-workout-item]') && childNode !== draggedItem)
            .reduce((closestNode, childNode) => {
                const box = childNode.getBoundingClientRect();
                const offset = clientY - box.top - (box.height / 2);
                if (offset < 0 && offset > closestNode.offset) {
                    return { offset, node: childNode };
                }

                return closestNode;
            }, { offset: Number.NEGATIVE_INFINITY, node: null }).node;
    }

    getDraggedItem(editorNode) {
        const itemId = editorNode.dataset.draggingItemId;
        if (!itemId) {
            return null;
        }

        return editorNode.querySelector(`[data-workout-item][data-item-id="${itemId}"]`);
    }

    getDraggedItemById(editorNode, itemId) {
        if (!itemId) {
            return null;
        }

        return editorNode.querySelector(`[data-workout-item][data-item-id="${itemId}"]`);
    }

    getDropzoneAtPoint(editorNode, clientX, clientY, draggedItem) {
        const targetNodes = document.elementsFromPoint(clientX, clientY);
        let dropzone = null;

        for (const targetNode of targetNodes) {
            if (!editorNode.contains(targetNode)) {
                continue;
            }

            dropzone = targetNode.closest?.('[data-workout-dropzone]') ?? null;
            if (!dropzone) {
                dropzone = targetNode.closest?.('[data-workout-block]')?.querySelector?.('[data-workout-block-steps]') ?? null;
            }

            if (dropzone) {
                break;
            }
        }

        if (!dropzone || !editorNode.contains(dropzone) || !this.canDropIntoZone(draggedItem, dropzone)) {
            return null;
        }

        return dropzone;
    }

    applyDraggingStyles(itemNode) {
        itemNode.classList.add('opacity-60');
        itemNode.style.transform = 'scale(1.01)';
        itemNode.style.boxShadow = '0 16px 40px rgba(15, 23, 42, 0.12)';
        itemNode.style.transition = 'transform 120ms ease, box-shadow 120ms ease';
        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'grabbing';
    }

    autoScrollPointerDrag(editorNode, clientY) {
        const scrollContainer = this.getPointerScrollContainer(editorNode);
        if (!scrollContainer) {
            return;
        }

        const rect = scrollContainer.getBoundingClientRect();
        const edgeThreshold = 72;
        const scrollStep = 24;

        if (clientY > rect.bottom - edgeThreshold) {
            scrollContainer.scrollTop += scrollStep;
        } else if (clientY < rect.top + edgeThreshold) {
            scrollContainer.scrollTop -= scrollStep;
        }
    }

    getPointerScrollContainer(editorNode) {
        return editorNode.closest('.modal-body') ?? editorNode.parentElement;
    }

    clearDraggingState(editorNode) {
        delete editorNode.dataset.draggingItemId;
        delete editorNode.dataset.dragHandleItemId;
        editorNode.querySelectorAll('[data-workout-item].opacity-60').forEach((itemNode) => {
            itemNode.classList.remove('opacity-60');
            itemNode.style.transform = '';
            itemNode.style.boxShadow = '';
            itemNode.style.transition = '';
        });
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        this.clearDropIndicator(editorNode);
    }

    clearPointerDragState(editorNode) {
        const pointerDragContext = editorNode._pointerDragContext;
        if (pointerDragContext?.handleNode?.releasePointerCapture && pointerDragContext.handleNode.hasPointerCapture?.(pointerDragContext.pointerId)) {
            pointerDragContext.handleNode.releasePointerCapture(pointerDragContext.pointerId);
        }
        editorNode._pointerDragContext = null;
        this.clearDraggingState(editorNode);
    }

    refreshDependentSummaries(targetNode, editorNode) {
        const blockNode = targetNode.closest('[data-workout-block]');
        if (blockNode) {
            this.refreshBlockSummary(blockNode);
        }

        const parentBlockNode = targetNode.closest('[data-workout-block-steps]')?.closest('[data-workout-block]');
        if (parentBlockNode && parentBlockNode !== blockNode) {
            this.refreshBlockSummary(parentBlockNode);
        }

        if (!blockNode && !parentBlockNode) {
            editorNode.querySelectorAll('[data-workout-block]').forEach((node) => this.refreshBlockSummary(node));
        }

        this.refreshPlannerAutofill(editorNode);
    }

    showDropIndicator(editorNode, dropzone, referenceNode) {
        const dropIndicator = this.getDropIndicator(editorNode);
        if (!dropIndicator || !dropzone) {
            return;
        }

        if (referenceNode) {
            dropzone.insertBefore(dropIndicator, referenceNode);
        } else {
            dropzone.appendChild(dropIndicator);
        }

        dropIndicator.style.display = 'block';
        dropIndicator.style.opacity = '1';
        dropzone.style.outline = '2px dashed rgba(245, 158, 11, 0.45)';
        dropzone.style.outlineOffset = '6px';
        dropzone.style.backgroundColor = 'rgba(245, 158, 11, 0.04)';

        const blockNode = dropzone.closest('[data-workout-block]');
        if (blockNode) {
            blockNode.style.boxShadow = '0 0 0 3px rgba(245, 158, 11, 0.12)';
            blockNode.style.borderColor = 'rgba(245, 158, 11, 0.55)';
        }
    }

    clearDropIndicator(editorNode) {
        const dropIndicator = this.getDropIndicator(editorNode);
        if (dropIndicator?.parentNode) {
            dropIndicator.parentNode.style.outline = '';
            dropIndicator.parentNode.style.outlineOffset = '';
            dropIndicator.parentNode.style.backgroundColor = '';
            dropIndicator.parentNode.removeChild(dropIndicator);
        }

        editorNode.querySelectorAll('[data-workout-block]').forEach((blockNode) => {
            blockNode.style.boxShadow = '';
            blockNode.style.borderColor = '';
        });
    }

    getDropIndicator(editorNode) {
        if (!editorNode._workoutDropIndicator) {
            const indicatorNode = document.createElement('div');
            indicatorNode.dataset.workoutDropIndicator = 'true';
            indicatorNode.style.height = '0';
            indicatorNode.style.margin = '0.35rem 0';
            indicatorNode.style.borderTop = '3px solid rgba(245, 158, 11, 0.9)';
            indicatorNode.style.borderRadius = '9999px';
            indicatorNode.style.boxShadow = '0 0 0 2px rgba(255,255,255,0.9)';
            indicatorNode.style.transition = 'opacity 120ms ease';
            editorNode._workoutDropIndicator = indicatorNode;
        }

        return editorNode._workoutDropIndicator;
    }

    createNodeFromTemplate(editorNode, templateNode) {
        const wrapperNode = document.createElement('div');
        wrapperNode.innerHTML = templateNode.innerHTML.trim().replaceAll('__ITEM_ID__', this.generateItemId(editorNode));

        return wrapperNode.firstElementChild;
    }

    ensureTemplatesInsideEditor(editorNode, topLevelDropzone = this.getTopLevelDropzone(editorNode)) {
        if (!topLevelDropzone) {
            return;
        }

        ['[data-workout-step-template]', '[data-workout-block-template]'].forEach((selector) => {
            if (editorNode.querySelector(selector)) {
                return;
            }

            const templateNode = editorNode.closest('form')?.querySelector(selector)
                ?? editorNode.parentElement?.querySelector(selector)
                ?? null;

            if (!templateNode || editorNode.contains(templateNode)) {
                return;
            }

            topLevelDropzone.appendChild(templateNode);
        });
    }

    getTemplateNode(editorNode, selector) {
        return editorNode.querySelector(selector)
            ?? editorNode.closest('form')?.querySelector(selector)
            ?? editorNode.parentElement?.querySelector(selector)
            ?? null;
    }

    assignFreshItemIds(editorNode, itemNode) {
        [itemNode, ...itemNode.querySelectorAll('[data-workout-item]')].forEach((workoutItemNode) => {
            const newItemId = this.generateItemId(editorNode);
            workoutItemNode.dataset.itemId = newItemId;
            workoutItemNode.querySelector('input[data-name-template$="[itemId]"]')?.setAttribute('value', newItemId);
            const itemIdInput = workoutItemNode.querySelector('input[data-name-template$="[itemId]"]');
            if (itemIdInput) {
                itemIdInput.value = newItemId;
            }

            if (workoutItemNode.matches('[data-workout-block]')) {
                workoutItemNode.dataset.collapsed = 'false';
                const blockStepsNode = workoutItemNode.querySelector('[data-workout-block-steps]');
                if (blockStepsNode) {
                    blockStepsNode.dataset.blockId = newItemId;
                }
                const blockContentNode = workoutItemNode.querySelector('[data-workout-block-content]');
                blockContentNode?.classList.remove('hidden');
            }
        });
    }

    generateItemId(editorNode) {
        const nextItemId = parseInt(editorNode.dataset.nextWorkoutItemId ?? String(Date.now()), 10) + 1;
        editorNode.dataset.nextWorkoutItemId = String(nextItemId);

        return `workout-item-${nextItemId}`;
    }

    getTopLevelDropzone(editorNode) {
        return editorNode.querySelector('[data-workout-top-level]');
    }
}
