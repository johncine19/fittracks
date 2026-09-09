/**
 * FitTrack Audio Notification System
 * High-performance Web Audio API synthesizer for clean, crisp, zero-latency notification chimes.
 */
(function() {
    'use strict';

    let audioCtx = null;
    let isMuted = false;

    try {
        isMuted = localStorage.getItem('fittracks_sound_muted') === '1';
    } catch (e) {
        isMuted = false;
    }

    function getAudioContext() {
        if (!audioCtx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                audioCtx = new AudioContextClass();
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        return audioCtx;
    }

    // Auto-unlock audio context on first user interaction to satisfy browser autoplay requirements
    const unlockEvents = ['click', 'keydown', 'touchstart', 'mousedown'];
    function unlockAudio() {
        const ctx = getAudioContext();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
        unlockEvents.forEach(evt => window.removeEventListener(evt, unlockAudio));
    }
    unlockEvents.forEach(evt => window.addEventListener(evt, unlockAudio, { passive: true }));

    function playTone(freq, type, startTime, duration, peakGain) {
        const ctx = getAudioContext();
        if (!ctx) return;

        try {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = type || 'sine';
            osc.frequency.setValueAtTime(freq, startTime);

            // Smooth envelope attack and exponential decay
            gain.gain.setValueAtTime(0.0001, startTime);
            gain.gain.linearRampToValueAtTime(peakGain || 0.18, startTime + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(startTime);
            osc.stop(startTime + duration);
        } catch (err) {
            // Gracefully ignore audio hardware errors
        }
    }

    function play(type = 'chime') {
        if (isMuted) return;
        const ctx = getAudioContext();
        if (!ctx) return;

        const now = ctx.currentTime + 0.01;

        if (type === 'ready' || type === 'your_turn') {
            // Uplifting 3-tone arpeggio fanfare for equipment ready / turn in queue (G5 -> C6 -> E6)
            playTone(783.99, 'sine', now, 0.32, 0.22);
            playTone(1046.50, 'sine', now + 0.11, 0.36, 0.24);
            playTone(1318.51, 'sine', now + 0.22, 0.65, 0.28);
            // Shimmering overtone
            playTone(1567.98, 'triangle', now + 0.23, 0.50, 0.10);
        } else if (type === 'success' || type === 'started' || type === 'finished') {
            // Uplifting completion chime (E5 -> A5)
            playTone(659.25, 'sine', now, 0.22, 0.18);
            playTone(880.00, 'sine', now + 0.10, 0.45, 0.24);
            playTone(1318.51, 'triangle', now + 0.11, 0.32, 0.08);
        } else if (type === 'warning' || type === 'alert' || type === 'expired') {
            // Gentle double ping
            playTone(880.00, 'sine', now, 0.18, 0.22);
            playTone(739.99, 'sine', now + 0.14, 0.34, 0.24);
        } else {
            // Standard notification chime: C6 -> E6 modern bell
            playTone(1046.50, 'sine', now, 0.28, 0.20);
            playTone(1318.51, 'sine', now + 0.09, 0.52, 0.25);
            playTone(2093.00, 'triangle', now + 0.10, 0.38, 0.07);
        }
    }

    function setMuted(muted) {
        isMuted = !!muted;
        try {
            localStorage.setItem('fittracks_sound_muted', isMuted ? '1' : '0');
        } catch (e) {}
        updateButtons();
    }

    function toggleMute() {
        setMuted(!isMuted);
        if (!isMuted) {
            play('chime');
        }
        return isMuted;
    }

    function updateButtons() {
        document.querySelectorAll('.btn-sound-toggle').forEach(btn => {
            btn.setAttribute('aria-label', isMuted ? 'Unmute notification sounds' : 'Mute notification sounds');
            btn.setAttribute('title', isMuted ? 'Sound: Muted (Click to unmute)' : 'Sound: Enabled (Click to mute)');
            btn.classList.toggle('is-muted', isMuted);

            const iconWrap = btn.querySelector('.sound-toggle-icon');
            if (iconWrap) {
                iconWrap.innerHTML = isMuted ?
                    '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>' :
                    '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
            }

            const textSpan = btn.querySelector('.sound-toggle-text');
            if (textSpan) {
                textSpan.textContent = isMuted ? 'Muted' : 'Sound On';
            }
        });
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-sound-toggle');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            toggleMute();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateButtons);
    } else {
        updateButtons();
    }

    window.FitTrackAudio = {
        play: play,
        isMuted: function() { return isMuted; },
        setMuted: setMuted,
        toggleMute: toggleMute,
        updateButtons: updateButtons
    };

    window.playNotifSound = function(type) {
        play(type);
    };
})();
