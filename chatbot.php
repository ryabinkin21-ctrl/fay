<?php
require_once __DIR__ . '/includes/db.php';      // $pdo (used by lang.php for prefs)
require_once __DIR__ . '/includes/header.php';  // opens <div class="page"><main>

/* render a grid of recommendation cards from the session log
   (must match the markup produced by addMovies() in the JS below) */
function render_rec_grid(array $movies, string $base): void {
    if (!$movies) return;
    echo '<div class="rec-grid">';
    foreach ($movies as $m) {
        $id     = (int)($m['tmdb_id'] ?? 0);
        $title  = htmlspecialchars((string)($m['title'] ?? ''), ENT_QUOTES);
        $reason = htmlspecialchars((string)($m['reason'] ?? ''), ENT_QUOTES);
        $genre  = (string)($m['genre'] ?? '');
        $year   = (int)($m['year'] ?? 0);
        $rating = (float)($m['rating'] ?? 0);
        $poster = (string)($m['poster_url'] ?? '');
        $isTv   = ($m['media_type'] ?? 'movie') === 'tv';
        $kind   = $isTv ? t('kind_tv') : t('kind_movie');
        $meta   = htmlspecialchars(trim(implode(' · ', array_filter([$kind, $year ?: '', $genre]))), ENT_QUOTES);

        $tag    = $id ? 'a' : 'div';
        $typeQs = $isTv ? '&type=tv' : '';
        $href   = $id ? ' href="' . htmlspecialchars($base . '/movie.php?tmdb_id=' . $id . $typeQs, ENT_QUOTES) . '"' : '';
        echo "<{$tag} class=\"rec-card\"{$href}>";
        echo '<div class="rec-poster">';
        echo $poster
            ? '<img src="' . htmlspecialchars($poster, ENT_QUOTES) . '" alt="" loading="lazy">'
            : '<div class="rec-noposter">' . $title . '</div>';
        if ($rating > 0) {
            echo '<span class="rec-rating">★ ' . number_format($rating, 1) . '</span>';
        }
        echo '</div><div class="rec-body">';
        echo '<div class="rec-title">' . $title . '</div>';
        if ($meta !== '')   echo '<div class="rec-meta">' . $meta . '</div>';
        if ($reason !== '') echo '<div class="rec-reason">' . $reason . '</div>';
        echo "</div></{$tag}>";
    }
    echo '</div>';
}
?>

<style>
/* ── FAY · AI recommendation chatbot ─────────────────────────── */
.chat-wrap {
    max-width: 820px;
    margin: 0 auto;
    padding: 1.5rem 1rem 0;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 70px);   /* viewport minus site header */
    min-height: 480px;
}
.chat-head {
    text-align: center;
    margin-bottom: 1rem;
}
.chat-head h1 {
    font-size: 1.5rem;
    color: var(--text);
    letter-spacing: .04em;
    margin: 0 0 .25rem;
}
.chat-head p {
    color: var(--muted);
    font-size: .9rem;
    margin: 0;
}

.chat-messages {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 1rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    display: flex;
    flex-direction: column;
    gap: .75rem;
}

.msg {
    max-width: 80%;
    padding: .65rem .9rem;
    border-radius: var(--radius-lg);
    line-height: 1.5;
    font-size: .94rem;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.msg.user {
    align-self: flex-end;
    background: var(--gold);
    color: #0a0a0a;
    border-bottom-right-radius: 2px;
}
.msg.assistant {
    align-self: flex-start;
    background: var(--bg-raised);
    color: var(--text);
    border: 1px solid var(--border);
    border-bottom-left-radius: 2px;
}

/* typing / loading indicator */
.msg.loading { display: flex; gap: .3rem; align-items: center; }
.msg.loading span {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--muted);
    animation: chatBlink 1.2s infinite ease-in-out both;
}
.msg.loading span:nth-child(2) { animation-delay: .2s; }
.msg.loading span:nth-child(3) { animation-delay: .4s; }
@keyframes chatBlink {
    0%, 80%, 100% { opacity: .25; transform: scale(.8); }
    40%           { opacity: 1;   transform: scale(1); }
}

/* ── movie recommendation cards ───────────────────────────────── */
.rec-grid {
    align-self: stretch;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: .75rem;
    margin-top: .25rem;
}
.rec-card {
    display: flex;
    flex-direction: column;
    background: var(--bg-raised);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    text-decoration: none;
    color: var(--text);
    transition: border-color .2s, transform .2s;
}
.rec-card:hover { border-color: var(--gold); transform: translateY(-2px); }
.rec-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    background: var(--bg-card);
}
.rec-poster img { width: 100%; height: 100%; object-fit: cover; display: block; }
.rec-poster .rec-noposter {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 100%;
    color: var(--muted); font-size: .8rem; text-align: center; padding: .5rem;
}
.rec-rating {
    position: absolute; top: .4rem; right: .4rem;
    background: rgba(0,0,0,.75); color: var(--gold);
    font-size: .75rem; font-weight: 700;
    padding: .12rem .4rem; border-radius: 999px;
}
.rec-body { padding: .55rem .6rem .65rem; display: flex; flex-direction: column; gap: .2rem; }
.rec-title { font-size: .85rem; font-weight: 600; line-height: 1.25; }
.rec-meta  { font-size: .72rem; color: var(--muted); }
.rec-reason {
    font-size: .74rem; color: var(--text); opacity: .85;
    margin-top: .15rem; line-height: 1.35;
}

.chat-input {
    display: flex;
    gap: .6rem;
    padding: 1rem 0;
}
.chat-input textarea {
    flex: 1 1 auto;
    resize: none;
    height: 48px;
    max-height: 140px;
    padding: .75rem .9rem;
    background: var(--bg-raised);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font: inherit;
    font-size: .94rem;
    line-height: 1.4;
}
.chat-input textarea:focus {
    outline: none;
    border-color: var(--border-hi);
}
.chat-send {
    flex: 0 0 auto;
    padding: 0 1.3rem;
    background: var(--gold);
    color: #0a0a0a;
    border: none;
    border-radius: var(--radius);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .2s;
}
.chat-send:hover:not(:disabled) { opacity: .88; }
.chat-send:disabled { opacity: .45; cursor: not-allowed; }

/* logged-out notice */
.chat-login-notice {
    max-width: 460px;
    margin: 4rem auto;
    text-align: center;
    padding: 2.5rem 2rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    color: var(--text);
}
.chat-login-notice p { color: var(--muted); margin: 0 0 1.25rem; }
.chat-login-notice a {
    display: inline-block;
    padding: .6rem 1.4rem;
    background: var(--gold);
    color: #0a0a0a;
    border-radius: var(--radius);
    font-weight: 600;
    text-decoration: none;
}
</style>

<?php if (!isset($_SESSION['user_id'])): ?>

    <div class="chat-login-notice">
        <p><?php echo t('chat_login_required'); ?></p>
        <a href="<?php echo $base; ?>/login.php"><?php echo t('nav_login'); ?></a>
    </div>

<?php else: ?>

    <div class="chat-wrap">
        <div class="chat-head">
            <h1><?php echo t('chat_title'); ?></h1>
            <p><?php echo t('chat_subtitle'); ?></p>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="msg assistant"><?php echo t('chat_greeting'); ?></div>
            <?php
            /* replay the conversation stored in the session (persists until logout) */
            foreach (($_SESSION['chat_log'] ?? []) as $entry):
                if (($entry['role'] ?? '') === 'user'):
                    ?>
                    <div class="msg user"><?php echo htmlspecialchars((string)($entry['text'] ?? '')); ?></div>
                    <?php
                elseif (($entry['role'] ?? '') === 'assistant'):
                    $comment = (string)($entry['comment'] ?? '');
                    if ($comment !== ''):
                        ?>
                        <div class="msg assistant"><?php echo htmlspecialchars($comment); ?></div>
                        <?php
                    endif;
                    render_rec_grid($entry['movies'] ?? [], $base);
                endif;
            endforeach;
            ?>
        </div>

        <form class="chat-input" id="chatForm">
            <textarea id="chatInput" placeholder="<?php echo t('chat_placeholder'); ?>"
                      autocomplete="off" rows="1"></textarea>
            <button type="submit" class="chat-send" id="chatSend"><?php echo t('chat_send'); ?></button>
        </form>
    </div>

    <script>
    (function () {
        const form     = document.getElementById('chatForm');
        const input    = document.getElementById('chatInput');
        const sendBtn  = document.getElementById('chatSend');
        const messages = document.getElementById('chatMessages');
        const base       = <?php echo json_encode($base); ?>;
        const KIND_MOVIE = <?php echo json_encode(t('kind_movie')); ?>;
        const KIND_TV    = <?php echo json_encode(t('kind_tv')); ?>;

        // escape for both text and double-quoted attribute contexts
        function esc(s) {
            return (s == null ? '' : String(s))
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // render a grid of movie recommendation cards
        function addMovies(movies) {
            const grid = document.createElement('div');
            grid.className = 'rec-grid';

            movies.forEach(m => {
                const isTv   = m.media_type === 'tv';
                const tag    = m.tmdb_id ? 'a' : 'div';
                const card   = document.createElement(tag);
                card.className = 'rec-card';
                if (m.tmdb_id) {
                    card.href = base + '/movie.php?tmdb_id=' + m.tmdb_id + (isTv ? '&type=tv' : '');
                }

                const rating = (m.rating && m.rating > 0)
                    ? `<span class="rec-rating">★ ${Number(m.rating).toFixed(1)}</span>` : '';
                const poster = m.poster_url
                    ? `<img src="${esc(m.poster_url)}" alt="" loading="lazy">`
                    : `<div class="rec-noposter">${esc(m.title)}</div>`;
                const meta = [isTv ? KIND_TV : KIND_MOVIE, m.year || '', m.genre || ''].filter(Boolean).join(' · ');

                card.innerHTML =
                    `<div class="rec-poster">${poster}${rating}</div>` +
                    `<div class="rec-body">` +
                        `<div class="rec-title">${esc(m.title)}</div>` +
                        (meta ? `<div class="rec-meta">${esc(meta)}</div>` : '') +
                        (m.reason ? `<div class="rec-reason">${esc(m.reason)}</div>` : '') +
                    `</div>`;
                grid.appendChild(card);
            });

            messages.appendChild(grid);
            scrollToBottom();
        }

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addMessage(role, text) {
            const el = document.createElement('div');
            el.className = 'msg ' + role;
            el.textContent = text;
            messages.appendChild(el);
            scrollToBottom();
            return el;
        }

        function addLoading() {
            const el = document.createElement('div');
            el.className = 'msg assistant loading';
            el.innerHTML = '<span></span><span></span><span></span>';
            messages.appendChild(el);
            scrollToBottom();
            return el;
        }

        // auto-grow textarea
        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        });

        // Enter sends, Shift+Enter = newline
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.requestSubmit();
            }
        });

        async function send(text) {
            addMessage('user', text);

            sendBtn.disabled = true;
            input.disabled   = true;
            const loadingEl  = addLoading();

            try {
                const res = await fetch('api/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text })
                });

                const data = await res.json();
                loadingEl.remove();

                if (!res.ok || data.error) {
                    addMessage('assistant', '⚠ ' + (data.error || 'Error'));
                } else {
                    const comment = data.comment || '';
                    const movies  = Array.isArray(data.movies) ? data.movies : [];

                    if (comment) addMessage('assistant', comment);
                    if (movies.length) addMovies(movies);
                    if (!comment && !movies.length) {
                        addMessage('assistant', '…');
                    }
                    // history is persisted server-side in the PHP session
                }
            } catch (err) {
                loadingEl.remove();
                addMessage('assistant', '⚠ ' + err.message);
            } finally {
                sendBtn.disabled = false;
                input.disabled   = false;
                input.focus();
            }
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const text = input.value.trim();
            if (!text) return;
            input.value = '';
            input.style.height = 'auto';
            send(text);
        });

        input.focus();
    })();
    </script>

<?php endif; ?>

</main></div></body></html>
