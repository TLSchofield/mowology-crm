<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Messages';
$activePage = 'messages';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="row mb-3">
  <div class="col">
    <h4 class="mb-0 d-flex align-items-center gap-2">
      <i data-feather="message-circle" class="text-muted" style="width:20px;height:20px"></i>
      Team Messages
    </h4>
    <p class="text-muted small mb-0 mt-1">Send and receive messages with other team members.</p>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm mw-btn-primary" onclick="openCompose()">
      <i data-feather="edit-3" style="width:14px;height:14px;margin-right:4px"></i>Compose
    </button>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mw-msg-tabs mb-3" id="msgTabs">
  <li class="nav-item">
    <a class="nav-link active" href="#" onclick="switchTab('inbox',this);return false">
      Inbox <span class="badge badge-pill mw-badge-unread d-none" id="unreadBadge">0</span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="#" onclick="switchTab('sent',this);return false">Sent</a>
  </li>
</ul>

<!-- Message list -->
<div class="card mw-msg-card">
  <div class="card-body p-0" id="msgList">
    <div class="mw-msg-empty py-5 text-center text-muted">
      <i data-feather="inbox" style="width:40px;height:40px;opacity:.3"></i>
      <p class="mt-2 mb-0">Loading messages…</p>
    </div>
  </div>
  <div class="card-footer d-flex align-items-center justify-content-between py-2 px-3" id="msgPager" style="display:none!important">
    <small class="text-muted" id="msgPagerInfo"></small>
    <div>
      <button class="btn btn-sm btn-outline-secondary mw-btn-prev" onclick="changePage(-1)">‹ Prev</button>
      <button class="btn btn-sm btn-outline-secondary mw-btn-next" onclick="changePage(1)">Next ›</button>
    </div>
  </div>
</div>

<!-- Message detail modal -->
<div class="modal fade" id="msgModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="msgModalSubject"></h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2" id="msgModalMeta"></p>
        <div id="msgModalBody" style="white-space:pre-wrap;line-height:1.6"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm mw-btn-primary" id="msgReplyBtn" onclick="replyTo()">Reply</button>
        <button class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Compose modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New Message</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-group mb-3">
          <label class="form-label">To</label>
          <select class="form-control" id="composeTo">
            <option value="">Select team member…</option>
          </select>
        </div>
        <div class="form-group mb-3">
          <label class="form-label">Subject</label>
          <input type="text" class="form-control" id="composeSubject" placeholder="(optional)">
        </div>
        <div class="form-group mb-0">
          <label class="form-label">Message</label>
          <textarea class="form-control" id="composeBody" rows="5" placeholder="Write your message…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm mw-btn-primary" onclick="sendMessage()">
          <i data-feather="send" style="width:13px;height:13px;margin-right:3px"></i>Send
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
var csrfToken = '<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>';
var currentTab  = 'inbox';
var currentPage = 1;
var totalPages  = 1;
var replyToId   = null;
var replyToName = '';

function switchTab(tab, el) {
    currentTab  = tab;
    currentPage = 1;
    document.querySelectorAll('#msgTabs .nav-link').forEach(a => a.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('msgReplyBtn').style.display = tab === 'inbox' ? '' : 'none';
    loadMessages();
}

function changePage(delta) {
    var next = currentPage + delta;
    if (next < 1 || next > totalPages) return;
    currentPage = next;
    loadMessages();
}

function loadMessages() {
    var list = document.getElementById('msgList');
    list.innerHTML = '<div class="mw-msg-empty py-5 text-center text-muted"><p>Loading…</p></div>';

    fetch('/crm/api/messages.php?action=' + currentTab + '&page=' + currentPage)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { list.innerHTML = '<div class="mw-msg-empty py-4 text-center text-muted"><p>Error loading messages.</p></div>'; return; }
            totalPages = data.pages || 1;
            renderMessages(data.messages, currentTab);
            updatePager(data);
            if (currentTab === 'inbox') updateUnreadBadge();
        });
}

function renderMessages(msgs, tab) {
    var list = document.getElementById('msgList');
    if (!msgs || !msgs.length) {
        list.innerHTML = '<div class="mw-msg-empty py-5 text-center text-muted"><i data-feather="inbox" style="width:36px;height:36px;opacity:.25"></i><p class="mt-2 mb-0">No messages yet.</p></div>';
        if (window.feather) feather.replace();
        return;
    }

    var html = '<div class="list-group list-group-flush">';
    msgs.forEach(function(m) {
        var isUnread = tab === 'inbox' && m.is_read == 0;
        var personLabel = tab === 'inbox' ? 'From: ' + h(m.from_name) : 'To: ' + h(m.to_name);
        var date = new Date(m.created_at).toLocaleDateString('en-CA', {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
        html += '<button class="list-group-item list-group-item-action mw-msg-row' + (isUnread ? ' mw-msg-unread' : '') + '" onclick="viewMessage(' + JSON.stringify(m) + ',' + JSON.stringify(tab) + ')">';
        html += '<div class="d-flex align-items-start">';
        if (isUnread) html += '<span class="mw-msg-dot"></span>';
        html += '<div class="flex-grow-1 min-width-0">';
        html += '<div class="d-flex justify-content-between mb-1"><span class="mw-msg-person">' + personLabel + '</span><small class="text-muted ms-2 flex-shrink-0">' + date + '</small></div>';
        html += '<div class="mw-msg-subject' + (isUnread ? ' font-weight-bold' : '') + '">' + h(m.subject) + '</div>';
        html += '<div class="mw-msg-preview">' + h(m.body.substring(0,80)) + (m.body.length>80?'…':'') + '</div>';
        html += '</div></div></button>';
    });
    html += '</div>';
    list.innerHTML = html;
    if (window.feather) feather.replace();
}

function viewMessage(msg, tab) {
    document.getElementById('msgModalSubject').textContent = msg.subject || '(no subject)';
    var personLabel = tab === 'inbox'
        ? 'From: ' + msg.from_name + ' • ' + new Date(msg.created_at).toLocaleString('en-CA')
        : 'To: ' + msg.to_name + ' • ' + new Date(msg.created_at).toLocaleString('en-CA');
    document.getElementById('msgModalMeta').textContent = personLabel;
    document.getElementById('msgModalBody').textContent = msg.body;
    replyToId   = tab === 'inbox' ? msg.from_user_id : null;
    replyToName = tab === 'inbox' ? msg.from_name    : '';
    document.getElementById('msgReplyBtn').style.display = tab === 'inbox' ? '' : 'none';

    if (tab === 'inbox' && msg.is_read == 0) {
        fetch('/crm/api/messages.php?action=mark-read', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrfToken, ids: [msg.id]})
        }).then(() => { loadMessages(); updateUnreadBadge(); });
    }

    $('#msgModal').modal('show');
}

function replyTo() {
    if (!replyToId) return;
    $('#msgModal').modal('hide');
    openCompose(replyToId, replyToName, 'Re: ' + document.getElementById('msgModalSubject').textContent);
}

function openCompose(toId, toName, subject) {
    fetch('/crm/api/messages.php?action=users')
        .then(r => r.json())
        .then(data => {
            var sel = document.getElementById('composeTo');
            sel.innerHTML = '<option value="">Select team member…</option>';
            (data.users || []).forEach(function(u) {
                var opt = document.createElement('option');
                opt.value = u.id;
                opt.textContent = u.full_name + (u.role ? ' (' + u.role + ')' : '');
                sel.appendChild(opt);
            });
            if (toId) sel.value = toId;
            document.getElementById('composeSubject').value = subject || '';
            document.getElementById('composeBody').value = '';
            $('#composeModal').modal('show');
        });
}

function sendMessage() {
    var toId    = parseInt(document.getElementById('composeTo').value);
    var subject = document.getElementById('composeSubject').value.trim();
    var body    = document.getElementById('composeBody').value.trim();

    if (!toId)   { alert('Please select a recipient.'); return; }
    if (!body)   { alert('Please enter a message.'); return; }

    fetch('/crm/api/messages.php?action=compose', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: csrfToken, to_user_id: toId, subject: subject, body: body})
    }).then(r => r.json()).then(data => {
        if (data.success) {
            $('#composeModal').modal('hide');
            switchTab('sent', document.querySelectorAll('#msgTabs .nav-link')[1]);
        } else {
            alert('Failed to send: ' + (data.error || 'unknown error'));
        }
    });
}

function updateUnreadBadge() {
    fetch('/crm/api/messages.php?action=unread-count')
        .then(r => r.json())
        .then(data => {
            var badge = document.getElementById('unreadBadge');
            var sidebarBadge = document.querySelector('.mw-msg-sidebar-badge');
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.classList.remove('d-none');
                if (sidebarBadge) { sidebarBadge.textContent = data.count; sidebarBadge.style.display=''; }
            } else {
                badge.classList.add('d-none');
                if (sidebarBadge) sidebarBadge.style.display = 'none';
            }
        });
}

function updatePager(data) {
    var pager = document.getElementById('msgPager');
    var info  = document.getElementById('msgPagerInfo');
    if (data.total > 20) {
        pager.style.removeProperty('display');
        pager.style.display = '';
        info.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' (' + data.total + ' total)';
        document.querySelector('.mw-btn-prev').disabled = currentPage <= 1;
        document.querySelector('.mw-btn-next').disabled = currentPage >= totalPages;
    } else {
        pager.style.display = 'none';
    }
}

function h(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

// Init
document.addEventListener('DOMContentLoaded', function() {
    loadMessages();
    updateUnreadBadge();
    if (window.feather) feather.replace();
});
</script>

<?php include 'includes/appstack_footer.php'; ?>
