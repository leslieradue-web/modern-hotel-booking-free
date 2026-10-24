/**
 * MHBO Voice Input / TTS
 *
 * Web Speech API wrapper with graceful degradation.
 *
 * @package
 * @since   2.4.0
 */

/* global mhboChat */

( function () {
	'use strict';

	const cfg = typeof mhboChat !== 'undefined' ? mhboChat : {};
	const settings = cfg.settings || {};
	const s = cfg.strings || {};

	// ─────────────────────────────────────────────────────────────────────────
	// VoiceInput
	// ─────────────────────────────────────────────────────────────────────────

	class VoiceInput {
		constructor() {
			this.SpeechRecognition =
				window.SpeechRecognition ||
				window.webkitSpeechRecognition ||
				null;
			this.SpeechSynthesis = window.speechSynthesis || null;
			this.recognition = null;
			this.isListening = false;
			this.currentAudio = null; // Track active ElevenLabs/external audio
			this.ttsEnabled = false;
			this.silenceTimer = null;
			this.audioGenId = 0; // Generation counter to prevent out-of-order/stale audio playback

			// Clean up active microphone and audio when navigating away or unmounting.
			window.addEventListener( 'pagehide', () => {
				this.stop();
				this.stopSpeaking();
			} );
			window.addEventListener( 'beforeunload', () => {
				this.stop();
				this.stopSpeaking();
			} );
		}

		/**
		 * Check if Speech Recognition is supported.
		 * @return {boolean}
		 */
		isSupported() {
			return !! this.SpeechRecognition;
		}

		/**
		 * Start voice input.
		 * @param {function(string): void} onResult Called with the final/interim transcript.
		 * @param {function(string): void} [onError] Called with error message.
		 * @param {function(): void} [onEnd] Called when speech recognition session ends.
		 */
		start( onResult, onError, onEnd ) {
			// Barge-in (2026 BP): Always stop bot speech when guest starts speaking.
			this.stopSpeaking();

			if ( this.silenceTimer ) {
				clearTimeout( this.silenceTimer );
				this.silenceTimer = null;
			}

			if ( ! this.SpeechRecognition ) {
				if ( typeof onError === 'function' ) {
					onError(
						s.voiceNotSupported ||
							'Voice input is not supported in this browser.'
					);
				}
				return;
			}

			if ( this.isListening ) {
				this.stop();
				return;
			}

			const r = new this.SpeechRecognition();
			r.lang = this._getLanguage();
			r.interimResults = true;
			r.maxAlternatives = 1;
			r.continuous = true;

			this.recognition = r;
			this.isListening = true;
			this._setRecordingUI( true );

			let finalTranscript = '';

			r.onresult = ( event ) => {
				// Clear existing silence timer.
				if ( this.silenceTimer ) {
					clearTimeout( this.silenceTimer );
				}

				let interimTranscript = '';
				for (
					let i = event.resultIndex;
					i < event.results.length;
					++i
				) {
					if ( event.results[ i ].isFinal ) {
						finalTranscript += event.results[ i ][ 0 ].transcript;
					} else {
						interimTranscript += event.results[ i ][ 0 ].transcript;
					}
				}

				const fullResult = (
					finalTranscript + interimTranscript
				).trim();
				if ( fullResult ) {
					onResult( fullResult );

					// Silence detection: If no more speech for 2.8 seconds, stop and submit.
					this.silenceTimer = setTimeout( () => {
						this.stop();
						const form = document.querySelector(
							'.mhbo-chat-widget--open .mhbo-chat-input-row'
						) || document.querySelector( '.mhbo-chat-input-row' );
						if ( form ) {
							form.dispatchEvent(
								new Event( 'submit', {
									cancelable: true,
									bubbles: true,
								} )
							);
						}
					}, 2800 );
				}
			};

			r.onerror = ( event ) => {
				this._setRecordingUI( false );
				this.isListening = false;
				if ( event.error === 'no-speech' ) {
					// User paused without speaking; exit gracefully without raising error bubble.
					return;
				}
				let msg = s.voiceError || 'Voice input error.';
				if ( event.error === 'not-allowed' ) {
					msg =
						s.voicePermissionDenied ||
						'Microphone access was denied. Please allow microphone access in your browser settings.';
				} else if ( event.error === 'network' ) {
					msg =
						s.voiceNetworkError ||
						'Network error during voice recognition.';
				}
				if ( typeof onError === 'function' ) {
					onError( msg );
				}
			};

			r.onend = () => {
				this._setRecordingUI( false );
				this.isListening = false;
				if ( typeof onEnd === 'function' ) {
					onEnd();
				}
			};

			try {
				r.start();
			} catch ( err ) {
				this._setRecordingUI( false );
				this.isListening = false;
				if ( typeof onError === 'function' ) {
					onError( s.voiceError || 'Failed to start microphone recording.' );
				}
			}
		}

		/**
		 * Stop active voice recognition.
		 */
		stop() {
			if ( this.silenceTimer ) {
				clearTimeout( this.silenceTimer );
				this.silenceTimer = null;
			}
			if ( this.recognition ) {
				this.recognition.stop();
				this.recognition = null;
			}
			this.isListening = false;
			this._setRecordingUI( false );
		}

		/**
		 * Stop all active speech output (Browser TTS and ElevenLabs).
		 */
		stopSpeaking() {
			this.audioGenId = ( this.audioGenId || 0 ) + 1;
			if ( this.SpeechSynthesis ) {
				try {
					this.SpeechSynthesis.cancel();
				} catch ( _ ) {}
			}
			if ( this.currentAudio ) {
				try {
					this.currentAudio.pause();
				} catch ( _ ) {}
				this.currentAudio = null;
			}
		}

		/**
		 * Strip Markdown syntax, URLs, HTML tags, and code blocks before speaking.
		 * Prevents synthetic voices from reading symbols like asterisks, hashes, brackets, or URLs.
		 * @param {string} text
		 * @return {string}
		 */
		_cleanTextForSpeech( text ) {
			if ( ! text || typeof text !== 'string' ) {
				return '';
			}
			let clean = text;

			// Convert Markdown links [Anchor Text](https://...) to just "Anchor Text"
			clean = clean.replace( /\[([^\]]+)\]\([^)]+\)/g, '$1' );

			// Strip code blocks and inline code
			clean = clean.replace( /```[\s\S]*?```/g, '' );
			clean = clean.replace( /`[^`]+`/g, '' );

			// Strip raw URLs
			clean = clean.replace( /https?:\/\/\S+/g, '' );

			// Strip HTML tags
			clean = clean.replace( /<[^>]+>/g, '' );

			// Strip Markdown symbols and bullet points
			clean = clean.replace( /[*#_~>|]/g, '' );
			clean = clean.replace( /^[ \t]*[-+*]\s+/gm, '' );

			// Collapse multiple spaces and newlines
			clean = clean.replace( /\s+/g, ' ' ).trim();

			// Clamp to sensible length (600 chars) for speech conciseness
			if ( clean.length > 600 ) {
				const truncated = clean.substring( 0, 600 );
				const lastPeriod = truncated.lastIndexOf( '.' );
				if ( lastPeriod > 300 ) {
					clean = truncated.substring( 0, lastPeriod + 1 );
				} else {
					clean = truncated + '…';
				}
			}

			return clean;
		}

		/**
		 * Speak text via TTS (browser SpeechSynthesis or ElevenLabs Pro).
		 * @param {string} text
		 */
		speak( text ) {
			const clean = this._cleanTextForSpeech( text );
			if ( ! clean ) {
				return;
			}

this._speakBrowser( clean );
		}

		/**
		 * Resolve the active BCP-47 language tag at call-time.
		 * Reads document.documentElement.lang (set by Polylang / WPML / qTranslate)
		 * and falls back to the admin-configured default.
		 * @return {string}
		 */
		_getLanguage() {
			const htmlLang = document.documentElement.lang || '';
			if ( htmlLang ) {
				return htmlLang.replace( '_', '-' );
			}
			return (
				settings.pageLocale ||
				settings.language ||
				'en-US'
			).replace( '_', '-' );
		}

		/**
		 * Browser SpeechSynthesis TTS.
		 * @param {string} text
		 */
		_speakBrowser( text ) {
			if ( ! this.SpeechSynthesis ) {
				return;
			}
			try {
				this.SpeechSynthesis.cancel();
				const utter = new SpeechSynthesisUtterance( text );
				utter.lang = this._getLanguage();
				utter.onerror = () => {
					// Gracefully suppress speech cancellation on user barge-in.
				};
				this.SpeechSynthesis.speak( utter );
			} catch ( _ ) {}
		}

/**
		 * Toggle the recording UI indicator on all voice buttons.
		 * @param {boolean} active
		 */
		_setRecordingUI( active ) {
			const btns = document.querySelectorAll( '.mhbo-chat-voice-btn' );
			btns.forEach( ( btn ) => {
				btn.classList.toggle( 'mhbo-chat-voice-btn--recording', active );
				btn.setAttribute( 'aria-pressed', String( active ) );
				btn.setAttribute(
					'aria-label',
					active
						? s.stopVoice || 'Stop recording'
						: s.startVoice || 'Start voice input'
				);
				btn.setAttribute(
					'title',
					active
						? s.stopVoice || 'Stop recording'
						: s.startVoice || 'Voice input'
				);
			} );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Boot
	// ─────────────────────────────────────────────────────────────────────────

	const instance = new VoiceInput();

	function hideUnsupportedVoiceButtons() {
		document
			.querySelectorAll( '.mhbo-chat-voice-btn' )
			.forEach( ( btn ) => {
				btn.hidden = true;
				btn.style.display = 'none';
			} );
	}

	// Hide voice buttons if not supported.
	if ( ! instance.isSupported() ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', hideUnsupportedVoiceButtons );
		} else {
			hideUnsupportedVoiceButtons();
		}
	}

	// Expose globally so ChatWidget can use it.
	window.MhboVoiceInput = instance;
} )();
