import { Controller } from '@hotwired/stimulus';
import { clamp, createEdition, editedDuration, formatTime, mergedBreaks, parseTime, playableTime, round, startIsTrimmed, toPayload, validate } from '../../edition.js';

const BREAK_LENGTH = 10;
const TICK_STEPS = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'video', 'secondary', 'playIcon', 'currentTime', 'totalTime', 'preview', 'editedTime',
        'timeline', 'ruler', 'cutStart', 'cutEnd', 'trimStartHandle', 'trimEndHandle', 'breaksLane', 'marksLane', 'playhead',
        'trimStart', 'trimEnd', 'breaksList', 'breaksEmpty', 'marksList', 'marksEmpty',
        'dirty', 'invalid', 'discardButton', 'saveButton', 'addBreakButton', 'breaksBlocked', 'breakTemplate', 'markTemplate',
    ];

    static values = { saveUrl: String, layoutUrl: String, duration: Number, edition: Object, messages: Object };

    connect() {
        if (!this.hasVideoTarget) {
            return;
        }
        this.duration = this.durationValue > 0 ? this.durationValue : 0;
        this.saved = this.editionValue;
        this.reset();
        this.beforeUnload = (event) => {
            if (this.isDirty()) {
                event.preventDefault();
                event.returnValue = this.messagesValue.unsaved;
            }
        };
        window.addEventListener('beforeunload', this.beforeUnload);
    }

    disconnect() {
        if (this.beforeUnload) {
            window.removeEventListener('beforeunload', this.beforeUnload);
        }
        window.cancelAnimationFrame(this.frame);
        if (this.hasVideoTarget) {
            this.videoTarget.pause();
        }
    }

    reset() {
        this.edition = createEdition(this.saved, this.duration);
        this.snapshot = JSON.stringify(toPayload(this.edition));
        this.renderRuler();
        this.renderLists();
        this.render();
    }

    loaded() {
        const duration = this.videoTarget.duration;
        if (!Number.isFinite(duration) || duration <= 0 || Math.abs(duration - this.duration) < 0.05) {
            return;
        }
        const dirty = this.isDirty();
        this.duration = duration;
        if (dirty) {
            this.edition.trimming.end = clamp(this.edition.trimming.end || duration, 0, duration);
            this.renderRuler();
            this.render();
        } else {
            this.reset();
        }
    }

    get currentTime() {
        return this.videoTarget.currentTime;
    }

    seek(time) {
        this.videoTarget.currentTime = clamp(time, 0, this.duration);
        this.updateClock();
    }

    togglePlay() {
        if (!this.videoTarget.paused) {
            this.videoTarget.pause();

            return;
        }
        if (this.previewTarget.checked && null === playableTime(this.edition, this.currentTime)) {
            this.seek(this.edition.trimming.start);
        }
        this.videoTarget.play();
    }

    back() {
        this.seek(this.currentTime - 5);
    }

    forward() {
        this.seek(this.currentTime + 5);
    }

    played() {
        this.playIconTarget.classList.replace('fa-play', 'fa-pause');
        if (this.hasSecondaryTarget) {
            this.secondaryTarget.currentTime = this.currentTime;
            this.secondaryTarget.play().catch(() => {});
        }
        const tick = () => {
            this.updateClock();
            this.frame = window.requestAnimationFrame(tick);
        };
        tick();
    }

    paused() {
        this.playIconTarget.classList.replace('fa-pause', 'fa-play');
        window.cancelAnimationFrame(this.frame);
        if (this.hasSecondaryTarget) {
            this.secondaryTarget.pause();
        }
        this.updateClock();
    }

    sync() {
        if (this.hasSecondaryTarget && Math.abs(this.secondaryTarget.currentTime - this.currentTime) > 0.25) {
            this.secondaryTarget.currentTime = this.currentTime;
        }
    }

    timeUpdate() {
        this.updateClock();
        this.sync();
        if (!this.previewTarget.checked || this.videoTarget.paused) {
            return;
        }
        const next = playableTime(this.edition, this.currentTime);
        if (null === next) {
            this.videoTarget.pause();
            this.seek(this.edition.trimming.end);
        } else if (next - this.currentTime > 0.05) {
            this.seek(next);
        }
    }

    shortcut(event) {
        if (event.target.closest('input, select, textarea')) {
            return;
        }
        if (' ' === event.key) {
            event.preventDefault();
            this.togglePlay();
        } else if ('ArrowLeft' === event.key || 'ArrowRight' === event.key) {
            event.preventDefault();
            const step = event.shiftKey ? 5 : 1;
            this.seek(this.currentTime + ('ArrowLeft' === event.key ? -step : step));
        }
    }

    pointerDown(event) {
        if (!this.duration || 0 !== event.button) {
            return;
        }
        const handle = event.target.closest('[data-drag]');
        const drag = handle ? { kind: handle.dataset.drag, index: Number(handle.dataset.index), origin: this.timeAt(event.clientX) } : { kind: 'playhead' };
        if ('break-move' === drag.kind) {
            const item = this.edition.breaks[drag.index];
            drag.start = item.s;
            drag.length = item.e - item.s;
        }
        this.timelineTarget.setPointerCapture(event.pointerId);
        const move = (moveEvent) => this.drag(drag, this.timeAt(moveEvent.clientX));
        const up = () => {
            this.timelineTarget.removeEventListener('pointermove', move);
            this.timelineTarget.removeEventListener('pointerup', up);
            this.timelineTarget.removeEventListener('pointercancel', up);
            if ('playhead' !== drag.kind) {
                this.sortItems();
                this.renderLists();
                this.render();
            }
        };
        this.timelineTarget.addEventListener('pointermove', move);
        this.timelineTarget.addEventListener('pointerup', up);
        this.timelineTarget.addEventListener('pointercancel', up);
        event.preventDefault();
        this.drag(drag, this.timeAt(event.clientX));
    }

    drag(drag, time) {
        const value = round(time);
        const { trimming } = this.edition;
        switch (drag.kind) {
            case 'playhead':
                this.seek(time);

                return;
            case 'trim-start':
                trimming.start = Math.min(value, round(trimming.end - 0.1));
                break;
            case 'trim-end':
                trimming.end = Math.max(value, round(trimming.start + 0.1));
                break;
            case 'break-s':
                this.edition.breaks[drag.index].s = Math.min(value, round(this.edition.breaks[drag.index].e - 0.1));
                break;
            case 'break-e':
                this.edition.breaks[drag.index].e = Math.max(value, round(this.edition.breaks[drag.index].s + 0.1));
                break;
            case 'break-move': {
                const start = round(clamp(drag.start + time - drag.origin, 0, this.duration - drag.length));
                this.edition.breaks[drag.index].s = start;
                this.edition.breaks[drag.index].e = round(start + drag.length);
                break;
            }
            case 'mark':
                this.edition.marks[drag.index].s = value;
                break;
            default:
                return;
        }
        this.seek(time);
        this.render();
        this.renderListTimes();
    }

    timeAt(clientX) {
        const rect = this.timelineTarget.getBoundingClientRect();

        return clamp((clientX - rect.left) / rect.width, 0, 1) * this.duration;
    }

    trimStartHere() {
        if (this.currentTime < this.edition.trimming.end) {
            this.edition.trimming.start = round(this.currentTime);
            this.render();
        }
    }

    trimEndHere() {
        if (this.currentTime > this.edition.trimming.start) {
            this.edition.trimming.end = round(this.currentTime);
            this.render();
        }
    }

    resetTrimming() {
        this.edition.trimming = { start: 0, end: this.duration };
        this.render();
    }

    editTrimming() {
        const start = parseTime(this.trimStartTarget.value);
        const end = parseTime(this.trimEndTarget.value);
        const valid = null !== start && null !== end && clamp(end, 0, this.duration) > clamp(start, 0, this.duration);
        this.trimStartTarget.classList.toggle('is-invalid', !valid);
        this.trimEndTarget.classList.toggle('is-invalid', !valid);
        if (valid) {
            this.edition.trimming = { start: clamp(start, 0, this.duration), end: clamp(end, 0, this.duration) };
            this.render();
        }
    }

    addBreak() {
        const { start: trimStart, end: trimEnd } = this.edition.trimming;
        if (startIsTrimmed(this.edition)) {
            return;
        }
        const start = round(clamp(this.currentTime, trimStart, Math.max(trimStart, trimEnd - 0.1)));
        this.edition.breaks.push({ s: start, e: round(Math.min(start + BREAK_LENGTH, trimEnd)), text: '' });
        this.sortItems();
        this.renderLists();
        this.render();
    }

    addMark() {
        this.edition.marks.push({ s: round(this.currentTime), name: `${this.messagesValue.chapterText} ${this.edition.marks.length + 1}` });
        this.sortItems();
        this.renderLists();
        this.render();
        const index = this.edition.marks.findIndex((item) => item.s === round(this.currentTime));
        this.marksListTarget.querySelector(`[data-index="${index}"] [data-field="name"]`)?.select();
    }

    listClick(event) {
        const button = event.target.closest('[data-role]');
        if (!button) {
            return;
        }
        const { items, index } = this.itemOf(button);
        if ('go' === button.dataset.role) {
            this.seek(items[index].s);
        } else if ('remove' === button.dataset.role) {
            items.splice(index, 1);
            this.renderLists();
            this.render();
        }
    }

    listChange(event) {
        const field = event.target.dataset.field;
        if (!['s', 'e'].includes(field)) {
            return;
        }
        const { items, index } = this.itemOf(event.target);
        const time = parseTime(event.target.value);
        const item = items[index];
        const valid = null !== time && ('e' !== field || clamp(time, 0, this.duration) > item.s) && ('s' !== field || undefined === item.e || clamp(time, 0, this.duration) < item.e);
        event.target.classList.toggle('is-invalid', !valid);
        if (!valid) {
            return;
        }
        item[field] = round(clamp(time, 0, this.duration));
        this.sortItems();
        this.renderLists();
        this.render();
    }

    listInput(event) {
        const field = event.target.dataset.field;
        if (!['text', 'name'].includes(field)) {
            return;
        }
        const { items, index } = this.itemOf(event.target);
        items[index][field] = event.target.value;
        this.render();
    }

    itemOf(element) {
        const list = element.closest('[data-kind]');

        return { items: this.edition[list.dataset.kind], index: Number(element.closest('[data-index]').dataset.index) };
    }

    sortItems() {
        this.edition.breaks.sort((a, b) => a.s - b.s);
        this.edition.marks.sort((a, b) => a.s - b.s);
    }

    discard() {
        if (window.confirm(this.messagesValue.discard)) {
            this.reset();
        }
    }

    async save() {
        const error = validate(this.edition);
        if (null !== error) {
            window.no_success?.(this.messagesValue[error]);

            return;
        }
        this.saveButtonTarget.disabled = true;
        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(toPayload(this.edition)),
            });
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            const data = await response.json();
            this.saved = data.edition;
            this.reset();
            window.success?.(this.messagesValue.saved);
        } catch {
            window.no_success?.(this.messagesValue.saveError);
            this.render();
        }
    }

    async changeLayout(event) {
        const body = new FormData();
        body.append('paellalayout', event.target.value);
        try {
            const response = await fetch(this.layoutUrlValue, { method: 'POST', credentials: 'same-origin', body });
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            window.success?.(this.messagesValue.layoutSaved);
        } catch {
            window.no_success?.(this.messagesValue.layoutError);
        }
    }

    isDirty() {
        return !!this.edition && JSON.stringify(toPayload(this.edition)) !== this.snapshot;
    }

    percent(time) {
        return this.duration > 0 ? `${(clamp(time, 0, this.duration) / this.duration) * 100}%` : '0%';
    }

    updateClock() {
        this.currentTimeTarget.textContent = formatTime(this.currentTime);
        this.playheadTarget.style.left = this.percent(this.currentTime);
    }

    render() {
        const { trimming } = this.edition;
        this.totalTimeTarget.textContent = formatTime(this.duration);
        this.editedTimeTarget.textContent = formatTime(editedDuration(this.edition));
        this.cutStartTarget.style.width = this.percent(trimming.start);
        this.cutEndTarget.style.left = this.percent(trimming.end);
        this.cutEndTarget.style.right = '0';
        this.trimStartHandleTarget.style.left = this.percent(trimming.start);
        this.trimEndHandleTarget.style.left = this.percent(trimming.end);
        if (document.activeElement !== this.trimStartTarget) {
            this.trimStartTarget.value = formatTime(trimming.start);
            this.trimStartTarget.classList.remove('is-invalid');
        }
        if (document.activeElement !== this.trimEndTarget) {
            this.trimEndTarget.value = formatTime(trimming.end);
            this.trimEndTarget.classList.remove('is-invalid');
        }
        this.renderLanes();
        this.updateClock();

        const dirty = this.isDirty();
        const error = validate(this.edition);
        const trimmedStart = startIsTrimmed(this.edition);
        this.dirtyTarget.classList.toggle('d-none', !dirty);
        this.invalidTarget.classList.toggle('d-none', null === error);
        this.invalidTarget.lastElementChild.textContent = error ? this.messagesValue[error] : '';
        this.addBreakButtonTarget.disabled = trimmedStart;
        this.breaksBlockedTarget.classList.toggle('d-none', !trimmedStart);
        this.discardButtonTarget.disabled = !dirty;
        this.saveButtonTarget.disabled = !dirty || null !== error;
    }

    renderLanes() {
        const merged = mergedBreaks(this.edition);
        this.breaksLaneTarget.replaceChildren(...this.edition.breaks.map((item, index) => {
            const block = document.createElement('div');
            block.className = 'sve-break';
            block.dataset.drag = 'break-move';
            block.dataset.index = index;
            block.style.left = this.percent(item.s);
            block.style.width = `calc(${this.percent(item.e)} - ${this.percent(item.s)})`;
            block.title = `${formatTime(item.s)} – ${formatTime(item.e)}${item.text ? ` · ${item.text}` : ''}`;
            block.classList.toggle('sve-break-outside', !merged.some(([s, e]) => item.s < e && item.e > s));
            for (const edge of ['s', 'e']) {
                const handle = document.createElement('div');
                handle.className = `sve-break-edge sve-break-edge-${edge}`;
                handle.dataset.drag = `break-${edge}`;
                handle.dataset.index = index;
                block.append(handle);
            }

            return block;
        }));
        this.marksLaneTarget.replaceChildren(...this.edition.marks.map((item, index) => {
            const pin = document.createElement('div');
            pin.className = 'sve-mark';
            pin.dataset.drag = 'mark';
            pin.dataset.index = index;
            pin.style.left = this.percent(item.s);
            pin.title = `${formatTime(item.s)} · ${item.name}`;

            return pin;
        }));
    }

    renderLists() {
        this.breaksListTarget.replaceChildren(...this.edition.breaks.map((item, index) => {
            const row = this.breakTemplateTarget.content.firstElementChild.cloneNode(true);
            row.dataset.index = index;
            row.querySelector('[data-field="text"]').value = item.text;

            return row;
        }));
        this.marksListTarget.replaceChildren(...this.edition.marks.map((item, index) => {
            const row = this.markTemplateTarget.content.firstElementChild.cloneNode(true);
            row.dataset.index = index;
            row.querySelector('[data-field="name"]').value = item.name;

            return row;
        }));
        this.breaksEmptyTarget.classList.toggle('d-none', this.edition.breaks.length > 0);
        this.marksEmptyTarget.classList.toggle('d-none', this.edition.marks.length > 0);
        this.renderListTimes();
    }

    renderListTimes() {
        for (const [list, items] of [[this.breaksListTarget, this.edition.breaks], [this.marksListTarget, this.edition.marks]]) {
            list.querySelectorAll('[data-index]').forEach((row) => {
                const item = items[Number(row.dataset.index)];
                for (const field of ['s', 'e']) {
                    const input = row.querySelector(`[data-field="${field}"]`);
                    if (input && item && document.activeElement !== input) {
                        input.value = formatTime(item[field]);
                        input.classList.remove('is-invalid');
                    }
                }
            });
        }
    }

    renderRuler() {
        if (!this.duration) {
            this.rulerTarget.replaceChildren();

            return;
        }
        const step = TICK_STEPS.find((candidate) => this.duration / candidate <= 12) ?? TICK_STEPS[TICK_STEPS.length - 1];
        const ticks = [];
        for (let time = 0; time < this.duration - step / 2; time += step) {
            const tick = document.createElement('span');
            tick.className = 'sve-tick';
            tick.style.left = this.percent(time);
            tick.textContent = formatTime(time).replace(/\.0$/, '');
            ticks.push(tick);
        }
        this.rulerTarget.replaceChildren(...ticks);
    }
}
