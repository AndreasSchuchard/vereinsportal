// ── Galerie (Swap) + Lightbox ─────────────────────
let gvActive = 0;

function gvRender() {
    if (!vhlbImages.length) return;
    const main = vhlbImages[gvActive];
    document.getElementById('gv-main-img').src = main.src;
    document.getElementById('gv-main-img').alt = main.caption;
    document.getElementById('gv-main-caption').textContent = main.caption;

    const row = document.getElementById('gv-thumbs');
    row.innerHTML = '';
    vhlbImages.forEach((img, i) => {
        const div = document.createElement('div');
        div.className = 'gv-thumb' + (i === gvActive ? ' active' : '');
        div.innerHTML = `<img src="${img.src}" alt="${img.caption}" loading="lazy"><span class="gv-thumb-caption">${img.caption}</span>`;
        if (i !== gvActive) div.onclick = () => { gvActive = i; gvRender(); };
        row.appendChild(div);
    });
}
gvRender();

let vhlbIdx = 0;
function vhlbOpen(i) {
    vhlbIdx = (i !== undefined) ? i : gvActive;
    vhlbShow();
    document.getElementById('vhlb').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function vhlbClose() {
    document.getElementById('vhlb').style.display = 'none';
    document.body.style.overflow = '';
}
function vhlbShow() {
    if (!vhlbImages.length) return;
    const d = vhlbImages[vhlbIdx];
    document.getElementById('vhlb-img').src = d.src;
    document.getElementById('vhlb-cap').textContent = d.caption;
    document.getElementById('vhlb-prev').style.display = vhlbImages.length > 1 ? '' : 'none';
    document.getElementById('vhlb-next').style.display = vhlbImages.length > 1 ? '' : 'none';
}
function vhlbPrev() { vhlbIdx = (vhlbIdx - 1 + vhlbImages.length) % vhlbImages.length; vhlbShow(); }
function vhlbNext() { vhlbIdx = (vhlbIdx + 1) % vhlbImages.length; vhlbShow(); }
document.addEventListener('keydown', e => {
    if (document.getElementById('vhlb').style.display === 'flex') {
        if (e.key === 'Escape') vhlbClose();
        if (e.key === 'ArrowLeft') vhlbPrev();
        if (e.key === 'ArrowRight') vhlbNext();
    }
});

// ── Kalender ──────────────────────────────────────
let currentDate = new Date();
let selectedStart = null, selectedEnd = null;
let bookedDates = [], pendingDates = [];

function isAdminBlocked(dateStr) {
    if (adminBlockedDates.includes(dateStr)) return true;
    const d = new Date(dateStr + 'T00:00:00');
    for (const r of adminBlockedRanges) {
        if (r.from && r.to && d >= new Date(r.from + 'T00:00:00') && d <= new Date(r.to + 'T00:00:00')) return true;
    }
    return false;
}
const monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

async function loadBookedDates() {
    try {
        const res = await fetch('/calendar-data.php');
        const data = await res.json();
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            bookedDates  = data.confirmed || [];
            pendingDates = data.pending   || [];
            if (Array.isArray(data.blocked)) adminBlockedDates = data.blocked;
        } else if (Array.isArray(data)) { bookedDates = data; }
    } catch(e) {}
    renderCalendar();
}

function getDatesInRange(start, end) {
    const dates = [];
    if (!start) return dates;
    let cur = new Date(start + 'T00:00:00');
    const endD = new Date((end || start) + 'T00:00:00');
    while (cur <= endD) {
        const y = cur.getFullYear(), m = String(cur.getMonth()+1).padStart(2,'0'), d = String(cur.getDate()).padStart(2,'0');
        dates.push(`${y}-${m}-${d}`); cur.setDate(cur.getDate() + 1);
    }
    return dates;
}

function renderCalendar() {
    const year = currentDate.getFullYear(), month = currentDate.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;
    const cal = document.getElementById('calendar');
    while (cal.children.length > 7) cal.removeChild(cal.lastChild);
    const selRange = getDatesInRange(selectedStart, selectedEnd);
    const startDay = (new Date(year, month, 1).getDay() || 7) - 1;
    for (let i = 0; i < startDay; i++) cal.appendChild(document.createElement('div'));
    const today = new Date(); today.setHours(0,0,0,0);
    for (let day = 1; day <= daysInMonth; day++) {
        const el = document.createElement('div');
        el.className = 'calendar-day';
        el.textContent = day;
        const ds = `${year}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const thisDate = new Date(ds + 'T00:00:00');
        if (thisDate < today) {
            el.classList.add('cal-day-past');
        } else if (bookedDates.includes(ds)) {
            el.classList.add('booked'); el.title = 'Bereits gebucht';
        } else if (pendingDates.includes(ds)) {
            el.classList.add('pending'); el.title = 'Anfrage läuft';
        } else if (isAdminBlocked(ds)) {
            el.classList.add('cal-day-blocked');
            el.title = 'Dieser Tag ist nicht verfügbar';
        } else {
            el.onclick = () => selectDate(ds);
            if (selRange.includes(ds)) {
                if (ds === selectedStart || ds === selectedEnd) {
                    el.classList.add('cal-day-selected');
                } else {
                    el.classList.add('cal-day-in-range');
                }
            }
        }
        if (year === today.getFullYear() && month === today.getMonth() && day === today.getDate() && !selRange.includes(ds)) {
            el.classList.add('cal-day-today');
        }
        cal.appendChild(el);
    }
}

function previousMonth() { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); }
function nextMonth()     { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); }

function selectDate(ds) {
    if (!selectedStart || (selectedStart && selectedEnd)) {
        selectedStart = ds; selectedEnd = null;
    } else {
        if (ds < selectedStart) { selectedEnd = selectedStart; selectedStart = ds; }
        else { selectedEnd = ds; }
        const range = getDatesInRange(selectedStart, selectedEnd);
        const blocked = range.filter(d => bookedDates.includes(d));
        const adminBlockedInRange = range.filter(d => isAdminBlocked(d));
        if (blocked.length) {
            const fmt = new Date(blocked[0]+'T00:00:00').toLocaleDateString('de-DE',{day:'numeric',month:'long'});
            alert(`Der ${fmt} ist bereits gebucht. Bitte anderen Zeitraum wählen.`);
            selectedStart = ds; selectedEnd = null;
        } else if (adminBlockedInRange.length) {
            const fmt = new Date(adminBlockedInRange[0]+'T00:00:00').toLocaleDateString('de-DE',{day:'numeric',month:'long'});
            alert(`Der ${fmt} ist nicht verfügbar. Bitte anderen Zeitraum wählen.`);
            selectedStart = ds; selectedEnd = null;
        }
    }
    renderCalendar(); updateBookingForm();
}

function resetCalendarSelection() {
    selectedStart = null; selectedEnd = null;
    renderCalendar();
    document.getElementById('bookingForm').classList.remove('active');
}

function updateBookingForm() {
    if (!selectedStart) { document.getElementById('bookingForm').classList.remove('active'); return; }
    const dates = getDatesInRange(selectedStart, selectedEnd);
    let txt;
    if (!selectedEnd || selectedEnd === selectedStart) {
        txt = new Date(selectedStart+'T00:00:00').toLocaleDateString('de-DE',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
    } else {
        const fmt = {day:'numeric',month:'long',year:'numeric'};
        txt = new Date(selectedStart+'T00:00:00').toLocaleDateString('de-DE',fmt) + ' – ' + new Date(selectedEnd+'T00:00:00').toLocaleDateString('de-DE',fmt) + ` (${dates.length} Tage)`;
    }
    document.getElementById('selectedDate').textContent = txt;
    document.getElementById('bookingForm').classList.add('active');
}

async function submitBooking(event) {
    event.preventDefault();
    const form = event.target;
    const btn = form.querySelector('button[type="submit"]');
    if (!selectedStart) { alert('Bitte wählen Sie zuerst einen Termin.'); return; }
    btn.disabled = true; btn.textContent = 'Wird gesendet…';
    const data = {
        dates: getDatesInRange(selectedStart, selectedEnd),
        name:  form.querySelector('[name="name"]').value,
        email: form.querySelector('[name="email"]').value,
        phone: form.querySelector('[name="phone"]').value,
        purpose: form.querySelector('[name="purpose"]')?.value || '',
        guests: parseInt(form.querySelector('[name="guests"]')?.value) || 0,
    };
    try {
        const res  = await fetch('/booking.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)});
        const json = await res.json();
        if (json.status === 'ok') {
            document.getElementById('bookingForm').innerHTML = '<div class="booking-success"><p>✓ Anfrage gesendet!</p><p>Wir melden uns innerhalb von 48 Stunden bei Ihnen.</p><p style="margin-top:.6rem;font-size:.9rem">📩 Bitte prüfen Sie auch Ihren <strong>SPAM-/Junk-Ordner</strong> – unsere Antwort landet dort gelegentlich.</p></div>';
        } else if (json.message === 'date_unavailable') {
            btn.disabled = false; btn.textContent = 'Anfrage unverbindlich senden →';
            alert('Dieser Termin ist leider vergeben. Bitte anderen Zeitraum wählen.');
        } else {
            btn.disabled = false; btn.textContent = 'Anfrage unverbindlich senden →';
            alert('Fehler beim Senden. Bitte rufen Sie uns direkt an: +49 000 000 00 00');
        }
    } catch(e) {
        btn.disabled = false; btn.textContent = 'Anfrage unverbindlich senden →';
        alert('Verbindungsfehler. Bitte rufen Sie uns an: +49 000 000 00 00');
    }
}

loadBookedDates();
