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
        preloadTickingBuffer();
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

    // --- Seamless Gapless Web Audio Clock Ticking Engine ---
    let tickingBuffer = null;
    let tickingPromise = null;
    let tickingSource = null;
    let tickingGain = null;
    let isTickingActive = false;

    function loadTickingBuffer() {
        if (tickingBuffer) return Promise.resolve(tickingBuffer);
        if (tickingPromise) return tickingPromise;
        const ctx = getAudioContext();
        if (!ctx) return Promise.reject(new Error('AudioContext unavailable'));

        tickingPromise = fetch('assets/audio/clock%20ticking.mp3')
            .then(res => {
                if (!res.ok) throw new Error('Audio file HTTP ' + res.status);
                return res.arrayBuffer();
            })
            .then(ab => ctx.decodeAudioData(ab))
            .then(decoded => {
                tickingBuffer = decoded;
                return decoded;
            })
            .catch(err => {
                tickingPromise = null;
                throw err;
            });
        return tickingPromise;
    }

    function preloadTickingBuffer() {
        if (!tickingBuffer && !tickingPromise) {
            loadTickingBuffer().catch(() => {});
        }
    }

    function playSeamlessTicking(volume = 0.55, force = false) {
        if (isMuted && !force) return;
        const ctx = getAudioContext();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});

        isTickingActive = true;
        stopSeamlessTicking();
        isTickingActive = true;

        function startSource(buf) {
            if (!isTickingActive || (isMuted && !force)) return;
            try {
                tickingSource = ctx.createBufferSource();
                tickingSource.buffer = buf;
                tickingSource.loop = true;
                // Clock ticking MP3 has 16 ticks spaced exactly at 0.500s intervals.
                // Tick 1 is at 0.1472s; Tick 16 is at 7.6458s.
                // Looping from 0.0s to 7.9986s gives exactly 0.500s interval across the loop boundary with zero gap.
                tickingSource.loopStart = 0;
                tickingSource.loopEnd = (buf.duration >= 7.99) ? 7.9986 : buf.duration;

                tickingGain = ctx.createGain();
                tickingGain.gain.setValueAtTime(0.001, ctx.currentTime);
                tickingGain.gain.linearRampToValueAtTime(volume, ctx.currentTime + 0.04);

                tickingSource.connect(tickingGain);
                tickingGain.connect(ctx.destination);
                tickingSource.start(0);
            } catch (e) {
                fallbackHtml5Ticking(volume);
            }
        }

        function fallbackHtml5Ticking(vol) {
            if (!isTickingActive || (isMuted && !force)) return;
            try {
                if (!window._currentTickingAudio) {
                    window._currentTickingAudio = new Audio('assets/audio/clock%20ticking.mp3');
                    window._currentTickingAudio.loop = true;
                }
                window._currentTickingAudio.volume = vol;
                window._currentTickingAudio.currentTime = 0;
                window._currentTickingAudio.play().catch(() => {});
            } catch (err) {}
        }

        if (tickingBuffer) {
            startSource(tickingBuffer);
        } else {
            loadTickingBuffer()
                .then(buf => {
                    startSource(buf);
                })
                .catch(() => {
                    fallbackHtml5Ticking(volume);
                });
        }
    }

    function stopSeamlessTicking() {
        isTickingActive = false;
        if (tickingGain && tickingSource) {
            try {
                const ctx = getAudioContext();
                if (ctx && tickingGain) {
                    tickingGain.gain.linearRampToValueAtTime(0.001, ctx.currentTime + 0.04);
                }
                const s = tickingSource;
                setTimeout(() => {
                    try { s.stop(); s.disconnect(); } catch (e) {}
                }, 50);
            } catch (e) {
                try { tickingSource.stop(); } catch (err) {}
            }
        }
        tickingSource = null;
        tickingGain = null;

        if (window._currentTickingAudio) {
            try {
                window._currentTickingAudio.pause();
                window._currentTickingAudio.currentTime = 0;
            } catch (e) {}
        }
    }

    function play(type = 'chime') {
        if (isMuted) return;
        const ctx = getAudioContext();
        if (!ctx) return;

        const now = ctx.currentTime + 0.01;

        if (type === 'victory' || type === 'workout_complete' || type === 'crushed') {
            try {
                if (window._currentVictoryAudio) {
                    window._currentVictoryAudio.pause();
                    window._currentVictoryAudio.currentTime = 0;
                }
                const audio = new Audio('assets/audio/astralsynthesizer-11l-victory.mp3');
                audio.volume = 0.75;
                window._currentVictoryAudio = audio;
                const p = audio.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(() => {
                        // Fallback to Web Audio fanfare if autoplay policy blocks audio element
                        playTone(783.99, 'sine', now, 0.32, 0.22);
                        playTone(1046.50, 'sine', now + 0.11, 0.36, 0.24);
                        playTone(1318.51, 'sine', now + 0.22, 0.65, 0.28);
                        playTone(1567.98, 'triangle', now + 0.23, 0.50, 0.10);
                    });
                }
                return;
            } catch (e) {
                // Graceful fallback below
            }
        }

        if (type === 'ticking' || type === 'clock_ticking') {
            playSeamlessTicking(0.55);
            return;
        }

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
        if (isMuted) {
            stopSeamlessTicking();
            stopVictory();
        }
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

    function stopVictory() {
        if (window._currentVictoryAudio) {
            try {
                window._currentVictoryAudio.pause();
                window._currentVictoryAudio.currentTime = 0;
            } catch (e) {}
        }
    }

    function stopTicking() {
        stopSeamlessTicking();
        if (window._currentTickingAudio) {
            try {
                window._currentTickingAudio.pause();
                window._currentTickingAudio.currentTime = 0;
            } catch (e) {}
        }
    }

    window.FitTrackAudio = {
        play: play,
        playSeamlessTicking: playSeamlessTicking,
        stopSeamlessTicking: stopSeamlessTicking,
        stopVictory: stopVictory,
        stopTicking: stopTicking,
        isMuted: function() { return isMuted; },
        setMuted: setMuted,
        toggleMute: toggleMute,
        updateButtons: updateButtons
    };

    window.playNotifSound = function(type) {
        play(type);
    };
    window.stopVictorySound = stopVictory;
    window.stopTickingSound = stopTicking;
})();
