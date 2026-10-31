export function formatTime(seconds) {
    const tenths = Math.round(Math.max(0, seconds) * 10);
    const hours = Math.floor(tenths / 36000);
    const minutes = Math.floor((tenths % 36000) / 600);
    const secs = Math.floor((tenths % 600) / 10);
    const pad = (value) => String(value).padStart(2, '0');
    const clock = `${pad(minutes)}:${pad(secs)}.${tenths % 10}`;

    return hours > 0 ? `${hours}:${clock}` : clock;
}

export function parseTime(text) {
    const value = String(text).trim().replace(',', '.');
    if (!/^\d+(:\d{1,2}){0,2}(\.\d+)?$/.test(value)) {
        return null;
    }

    return value.split(':').reduce((total, part) => total * 60 + parseFloat(part), 0);
}

export function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

export function round(value) {
    return Math.round(value * 10) / 10;
}

export function createEdition(saved, duration) {
    const trimming = saved?.trimming && saved.trimming.end > saved.trimming.start
        ? { start: clamp(saved.trimming.start, 0, duration), end: clamp(saved.trimming.end, 0, duration) }
        : { start: 0, end: duration };

    return {
        trimming,
        breaks: (saved?.breaks ?? []).map((item) => ({ s: item.s, e: item.e, text: item.text ?? '' })),
        marks: (saved?.marks ?? []).map((item) => ({ s: item.s, name: item.name ?? '' })),
    };
}

export function mergedBreaks(edition) {
    const { start, end } = edition.trimming;
    const ranges = edition.breaks
        .map((item) => [Math.max(start, item.s), Math.min(end, item.e)])
        .filter(([s, e]) => e > s)
        .sort((a, b) => a[0] - b[0]);

    return ranges.reduce((merged, range) => {
        const last = merged[merged.length - 1];
        if (last && range[0] <= last[1]) {
            last[1] = Math.max(last[1], range[1]);
        } else {
            merged.push([...range]);
        }

        return merged;
    }, []);
}

export function editedDuration(edition) {
    const { start, end } = edition.trimming;

    return mergedBreaks(edition).reduce((total, [s, e]) => total - (e - s), end - start);
}

export function playableTime(edition, time) {
    const { start, end } = edition.trimming;
    if (time < start) {
        return start;
    }
    if (time >= end) {
        return null;
    }
    for (const [s, e] of mergedBreaks(edition)) {
        if (time >= s && time < e) {
            return e >= end ? null : e;
        }
    }

    return time;
}

export function toPayload(edition) {
    return {
        trimming: { start: round(edition.trimming.start), end: round(edition.trimming.end) },
        breaks: edition.breaks.map((item) => ({ s: round(item.s), e: round(item.e), text: item.text.trim() })),
        marks: edition.marks.map((item) => ({ s: round(item.s), name: item.name.trim() })),
    };
}

export function validate(edition) {
    if (edition.trimming.end <= edition.trimming.start) {
        return 'trimming';
    }
    if (edition.breaks.some((item) => item.e <= item.s)) {
        return 'breaks';
    }

    return null;
}
