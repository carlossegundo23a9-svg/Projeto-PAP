(function () {
  var THEME_STORAGE_KEY = "estel_sgp_theme";
  var SIDEBAR_STORAGE_KEY = "patri_sidebar_collapsed";
  var MOBILE_QUERY = "(max-width: 900px)";
  var mobileMediaQuery = null;

  function hasClass(element, className) {
    if (!element) {
      return false;
    }

    if (element.classList) {
      return element.classList.contains(className);
    }

    return (" " + element.className + " ").indexOf(" " + className + " ") > -1;
  }

  function addClass(element, className) {
    if (!element) {
      return;
    }

    if (element.classList) {
      element.classList.add(className);
      return;
    }

    if (!hasClass(element, className)) {
      element.className = (element.className + " " + className).replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
    }
  }

  function removeClass(element, className) {
    if (!element) {
      return;
    }

    if (element.classList) {
      element.classList.remove(className);
      return;
    }

    element.className = (" " + element.className + " ")
      .replace(" " + className + " ", " ")
      .replace(/\s+/g, " ")
      .replace(/^\s+|\s+$/g, "");
  }

  function toggleClass(element, className) {
    if (!element) {
      return false;
    }

    if (hasClass(element, className)) {
      removeClass(element, className);
      return false;
    }

    addClass(element, className);
    return true;
  }

  function forEachThemeToggleButton(callback) {
    var buttons = document.querySelectorAll(".js-theme-toggle");
    for (var i = 0; i < buttons.length; i += 1) {
      callback(buttons[i]);
    }
  }

  function forEachSidebarToggleButton(callback) {
    var buttons = document.querySelectorAll(".js-sidebar-toggle");
    for (var i = 0; i < buttons.length; i += 1) {
      callback(buttons[i]);
    }
  }

  function getStoredTheme() {
    try {
      var saved = localStorage.getItem(THEME_STORAGE_KEY);
      if (saved === "light" || saved === "dark") {
        return saved;
      }
    } catch (e) {
      // Ignore localStorage errors.
    }

    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
      return "dark";
    }

    return "light";
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch (e) {
      // Ignore localStorage errors.
    }
  }

  function applyTheme(theme) {
    var isDark = theme === "dark";

    if (isDark) {
      addClass(document.body, "theme-dark");
    } else {
      removeClass(document.body, "theme-dark");
    }

    forEachThemeToggleButton(function (button) {
      button.textContent = "";
      button.setAttribute("aria-label", isDark ? "Ativar modo claro" : "Ativar modo escuro");
      button.setAttribute("title", isDark ? "Ativar modo claro" : "Ativar modo escuro");
      button.setAttribute("aria-pressed", isDark ? "true" : "false");
    });
  }

  function toggleTheme() {
    var nextTheme = hasClass(document.body, "theme-dark") ? "light" : "dark";
    applyTheme(nextTheme);
    saveTheme(nextTheme);
  }

  function getSidebarCollapsedPreference() {
    try {
      return localStorage.getItem(SIDEBAR_STORAGE_KEY) === "1";
    } catch (e) {
      return false;
    }
  }

  function saveSidebarCollapsedPreference(isCollapsed) {
    try {
      localStorage.setItem(SIDEBAR_STORAGE_KEY, isCollapsed ? "1" : "0");
    } catch (e) {
      // Ignore localStorage errors.
    }
  }

  function isMobileViewport() {
    if (mobileMediaQuery) {
      return mobileMediaQuery.matches;
    }

    if (window.matchMedia) {
      return window.matchMedia(MOBILE_QUERY).matches;
    }

    return window.innerWidth <= 900;
  }

  function setSidebarButtonIcon(button, iconClass) {
    var icon = button.querySelector("i");
    if (!icon) {
      icon = document.createElement("i");
      icon.setAttribute("aria-hidden", "true");
      button.appendChild(icon);
    }
    icon.className = iconClass;
  }

  function updateSidebarToggleButtons() {
    var isMobile = isMobileViewport();
    var isCollapsed = hasClass(document.body, "sidebar-collapsed");
    var isOpen = hasClass(document.body, "sidebar-open");

    forEachSidebarToggleButton(function (button) {
      var label = "";
      var iconClass = "fas fa-angles-left";
      var pressed = false;

      if (isMobile) {
        label = isOpen ? "Fechar menu lateral" : "Abrir menu lateral";
        iconClass = isOpen ? "fas fa-xmark" : "fas fa-angles-right";
        pressed = isOpen;
      } else {
        label = isCollapsed ? "Expandir menu lateral" : "Recolher menu lateral";
        iconClass = isCollapsed ? "fas fa-angles-right" : "fas fa-angles-left";
        pressed = !isCollapsed;
      }

      button.setAttribute("aria-label", label);
      button.setAttribute("title", label);
      button.setAttribute("aria-pressed", pressed ? "true" : "false");
      setSidebarButtonIcon(button, iconClass);
    });
  }

  function ensureSidebarBackdrop() {
    if (!document.querySelector(".sidebar")) {
      return;
    }

    if (document.querySelector(".js-sidebar-backdrop")) {
      return;
    }

    var backdrop = document.createElement("div");
    backdrop.className = "sidebar-backdrop js-sidebar-backdrop";
    document.body.appendChild(backdrop);
  }

  function syncSidebarMode() {
    if (!document.querySelector(".sidebar")) {
      return;
    }

    if (isMobileViewport()) {
      removeClass(document.body, "sidebar-collapsed");
      removeClass(document.body, "sidebar-open");
    } else {
      removeClass(document.body, "sidebar-open");
      if (getSidebarCollapsedPreference()) {
        addClass(document.body, "sidebar-collapsed");
      } else {
        removeClass(document.body, "sidebar-collapsed");
      }
    }

    updateSidebarToggleButtons();
  }

  function closeSidebarMobile() {
    removeClass(document.body, "sidebar-open");
    updateSidebarToggleButtons();
  }

  function toggleSidebar() {
    if (isMobileViewport()) {
      toggleClass(document.body, "sidebar-open");
      updateSidebarToggleButtons();
      return;
    }

    var collapsed = toggleClass(document.body, "sidebar-collapsed");
    saveSidebarCollapsedPreference(collapsed);
    updateSidebarToggleButtons();
  }

  function initThemeToggle() {
    applyTheme(getStoredTheme());

    forEachThemeToggleButton(function (button) {
      if (button.addEventListener) {
        button.addEventListener("click", toggleTheme);
      } else {
        button.attachEvent("onclick", toggleTheme);
      }
    });
  }

  function initSidebarToggle() {
    if (!document.querySelector(".sidebar")) {
      return;
    }

    ensureSidebarBackdrop();

    if (window.matchMedia) {
      mobileMediaQuery = window.matchMedia(MOBILE_QUERY);
    }

    syncSidebarMode();

    forEachSidebarToggleButton(function (button) {
      if (button.getAttribute("data-sidebar-bound") === "1") {
        return;
      }

      button.setAttribute("data-sidebar-bound", "1");
      if (button.addEventListener) {
        button.addEventListener("click", toggleSidebar);
      } else {
        button.attachEvent("onclick", toggleSidebar);
      }
    });

    var backdrop = document.querySelector(".js-sidebar-backdrop");
    if (backdrop && backdrop.getAttribute("data-sidebar-bound") !== "1") {
      backdrop.setAttribute("data-sidebar-bound", "1");
      if (backdrop.addEventListener) {
        backdrop.addEventListener("click", closeSidebarMobile);
      } else {
        backdrop.attachEvent("onclick", closeSidebarMobile);
      }
    }

    if (document.body.getAttribute("data-sidebar-esc-bound") !== "1") {
      document.body.setAttribute("data-sidebar-esc-bound", "1");
      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeSidebarMobile();
        }
      });
    }

    if (mobileMediaQuery && mobileMediaQuery.addEventListener) {
      mobileMediaQuery.addEventListener("change", syncSidebarMode);
    } else if (mobileMediaQuery && mobileMediaQuery.addListener) {
      mobileMediaQuery.addListener(syncSidebarMode);
    } else {
      window.addEventListener("resize", syncSidebarMode);
    }
  }

  var confirmQueue = [];
  var confirmDialogState = null;

  function ensureToastContainer() {
    var existing = document.querySelector(".js-popup-toast-stack");
    if (existing) {
      return existing;
    }

    var container = document.createElement("div");
    container.className = "popup-toast-stack js-popup-toast-stack";
    container.setAttribute("aria-live", "polite");
    container.setAttribute("aria-atomic", "true");
    document.body.appendChild(container);
    return container;
  }

  function detectToastType(messageEl) {
    if (!messageEl) {
      return "info";
    }

    if (hasClass(messageEl, "msg-success")) {
      return "success";
    }

    if (hasClass(messageEl, "msg-error")) {
      return "error";
    }

    return "info";
  }

  function showPopupNotification(message, type, ttlMs) {
    var text = String(message || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
    if (text === "") {
      return;
    }

    var toastType = type === "success" || type === "error" || type === "info" ? type : "info";
    var duration = Number(ttlMs);
    if (!Number.isFinite(duration) || duration < 1000) {
      duration = 5200;
    }

    var stack = ensureToastContainer();
    var toast = document.createElement("div");
    toast.className = "popup-toast popup-toast-" + toastType;
    toast.setAttribute("role", "status");

    var toastText = document.createElement("p");
    toastText.className = "popup-toast-text";
    toastText.textContent = text;
    toast.appendChild(toastText);

    var closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "popup-toast-close";
    closeButton.setAttribute("aria-label", "Fechar notificação");
    closeButton.textContent = "\u00D7";
    toast.appendChild(closeButton);

    stack.appendChild(toast);
    window.requestAnimationFrame(function () {
      addClass(toast, "is-visible");
    });

    var removeToast = function () {
      removeClass(toast, "is-visible");
      window.setTimeout(function () {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 220);
    };

    if (closeButton.addEventListener) {
      closeButton.addEventListener("click", removeToast);
    } else {
      closeButton.attachEvent("onclick", removeToast);
    }

    window.setTimeout(removeToast, duration);
  }

  function initMessagePopups() {
    var messages = document.querySelectorAll(".msg[data-toast='1'], .msg[data-toast-only='1']");
    var i = 0;

    for (i = 0; i < messages.length; i += 1) {
      var messageEl = messages[i];
      if (messageEl.getAttribute("data-popup-toasted") === "1") {
        continue;
      }

      var text = String(messageEl.textContent || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
      if (text === "") {
        continue;
      }

      showPopupNotification(text, detectToastType(messageEl), 5600);
      messageEl.setAttribute("data-popup-toasted", "1");

      if (messageEl.getAttribute("data-toast-only") === "1") {
        messageEl.parentNode && messageEl.parentNode.removeChild(messageEl);
      }
    }

    window.appShowToast = showPopupNotification;
  }

  function extractInlineConfirmMessage(handlerCode) {
    var raw = String(handlerCode || "");
    var match = /confirm\s*\(\s*(['"])([\s\S]*?)\1\s*\)/i.exec(raw);
    if (!match || match.length < 3) {
      return "";
    }

    var value = String(match[2] || "");
    value = value.replace(/\\\\/g, "\\");
    value = value.replace(/\\"/g, "\"");
    value = value.replace(/\\'/g, "'");
    value = value.replace(/\\n/g, "\n");
    value = value.replace(/\\r/g, "");
    return value;
  }

  function normalizeLegacyInlineConfirmations() {
    var forms = document.querySelectorAll("form[onsubmit]");
    var i = 0;

    for (i = 0; i < forms.length; i += 1) {
      var form = forms[i];
      var onsubmit = String(form.getAttribute("onsubmit") || "");
      if (!/^\s*return\s+confirm\s*\(/i.test(onsubmit)) {
        continue;
      }

      var message = extractInlineConfirmMessage(onsubmit);
      if (message !== "" && !form.getAttribute("data-confirm-message")) {
        form.setAttribute("data-confirm-message", message);
      }
      form.removeAttribute("onsubmit");
    }
  }

  function ensureConfirmDialog() {
    if (confirmDialogState) {
      return confirmDialogState;
    }

    var backdrop = document.createElement("div");
    backdrop.className = "popup-confirm-backdrop";
    backdrop.hidden = true;

    var dialog = document.createElement("div");
    dialog.className = "popup-confirm-dialog";
    dialog.setAttribute("role", "dialog");
    dialog.setAttribute("aria-modal", "true");
    dialog.setAttribute("aria-labelledby", "popup-confirm-title");
    dialog.setAttribute("aria-describedby", "popup-confirm-message");

    var titleEl = document.createElement("h2");
    titleEl.className = "popup-confirm-title";
    titleEl.id = "popup-confirm-title";

    var messageEl = document.createElement("p");
    messageEl.className = "popup-confirm-message";
    messageEl.id = "popup-confirm-message";

    var actions = document.createElement("div");
    actions.className = "popup-confirm-actions";

    var cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.className = "btn btn-secondary";
    cancelBtn.textContent = "Cancelar";

    var confirmBtn = document.createElement("button");
    confirmBtn.type = "button";
    confirmBtn.className = "btn btn-primary";
    confirmBtn.textContent = "Confirmar";

    actions.appendChild(cancelBtn);
    actions.appendChild(confirmBtn);
    dialog.appendChild(titleEl);
    dialog.appendChild(messageEl);
    dialog.appendChild(actions);
    backdrop.appendChild(dialog);
    document.body.appendChild(backdrop);

    confirmDialogState = {
      backdrop: backdrop,
      dialog: dialog,
      titleEl: titleEl,
      messageEl: messageEl,
      cancelBtn: cancelBtn,
      confirmBtn: confirmBtn,
      activeRequest: null,
      hideTimer: null,
    };

    function closeDialog(result) {
      var state = confirmDialogState;
      if (!state || !state.activeRequest) {
        return;
      }

      var request = state.activeRequest;
      state.activeRequest = null;

      removeClass(state.backdrop, "is-visible");
      removeClass(document.body, "popup-confirm-open");

      if (state.hideTimer !== null) {
        window.clearTimeout(state.hideTimer);
      }
      state.hideTimer = window.setTimeout(function () {
        state.backdrop.hidden = true;
      }, 180);

      if (request.focusBack && request.focusBack.focus) {
        window.setTimeout(function () {
          try {
            request.focusBack.focus();
          } catch (e) {
            // Ignore focus restore errors.
          }
        }, 0);
      }

      request.resolve(result);
      processConfirmQueue();
    }

    if (confirmBtn.addEventListener) {
      confirmBtn.addEventListener("click", function () {
        closeDialog(true);
      });
      cancelBtn.addEventListener("click", function () {
        closeDialog(false);
      });
      backdrop.addEventListener("click", function (event) {
        if (event.target === backdrop) {
          closeDialog(false);
        }
      });
      document.addEventListener("keydown", function (event) {
        var state = confirmDialogState;
        if (!state || !state.activeRequest) {
          return;
        }

        if (event.key === "Escape") {
          event.preventDefault();
          closeDialog(false);
          return;
        }

        if (event.key === "Enter" && document.activeElement !== state.cancelBtn) {
          event.preventDefault();
          closeDialog(true);
        }
      });
    } else {
      confirmBtn.attachEvent("onclick", function () {
        closeDialog(true);
      });
      cancelBtn.attachEvent("onclick", function () {
        closeDialog(false);
      });
    }

    return confirmDialogState;
  }

  function processConfirmQueue() {
    var state = ensureConfirmDialog();
    if (state.activeRequest || confirmQueue.length === 0) {
      return;
    }

    var request = confirmQueue.shift();
    var options = request.options || {};
    var title = String(options.title || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
    var confirmText = String(options.confirmText || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
    var cancelText = String(options.cancelText || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");

    state.activeRequest = request;
    state.titleEl.textContent = title !== "" ? title : "Confirmação";
    state.messageEl.textContent = request.message;
    state.confirmBtn.textContent = confirmText !== "" ? confirmText : "Confirmar";
    state.cancelBtn.textContent = cancelText !== "" ? cancelText : "Cancelar";

    state.backdrop.hidden = false;
    if (state.hideTimer !== null) {
      window.clearTimeout(state.hideTimer);
      state.hideTimer = null;
    }
    window.requestAnimationFrame(function () {
      addClass(state.backdrop, "is-visible");
      addClass(document.body, "popup-confirm-open");
      window.setTimeout(function () {
        if (state.activeRequest) {
          state.confirmBtn.focus();
        }
      }, 20);
    });
  }

  function showSystemConfirm(message, options) {
    var text = String(message || "").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
    if (text === "") {
      return Promise.resolve(true);
    }

    return new Promise(function (resolve) {
      confirmQueue.push({
        message: text,
        options: options || {},
        resolve: resolve,
        focusBack: document.activeElement,
      });
      processConfirmQueue();
    });
  }

  function resolveLogoutUrl() {
    var logoutLinks = document.querySelectorAll("a[href*='logout.php']");
    if (logoutLinks.length > 0) {
      return logoutLinks[0].href;
    }

    var pathname = String(window.location.pathname || "");
    var marker = "/dashboard/";
    var markerPos = pathname.indexOf(marker);
    var basePath = markerPos >= 0 ? pathname.slice(0, markerPos) : "";

    return basePath + "/login/logout.php";
  }

  function confirmarLogoutGlobal() {
    var message = "Tem a certeza de que pretende terminar a sessão?";
    showSystemConfirm(message, {
      title: "Terminar Sessão",
      confirmText: "Sair",
      cancelText: "Cancelar",
    }).then(function (confirmed) {
      if (confirmed) {
        window.location.href = resolveLogoutUrl();
      }
    });
    return false;
  }

  function initGlobalActionConfirmations() {
    normalizeLegacyInlineConfirmations();
    window.confirmarLogout = confirmarLogoutGlobal;

    document.addEventListener("submit", function (event) {
      var target = event.target;
      if (!target || target.tagName !== "FORM") {
        return;
      }

      var confirmMessage = target.getAttribute("data-confirm-message");
      if (!confirmMessage) {
        return;
      }

      if (target.getAttribute("data-confirm-approved") === "1") {
        target.removeAttribute("data-confirm-approved");
        return;
      }

      event.preventDefault();

      if (target.getAttribute("data-confirm-pending") === "1") {
        return;
      }

      target.setAttribute("data-confirm-pending", "1");
      showSystemConfirm(confirmMessage, {
        title: target.getAttribute("data-confirm-title") || "Confirmação",
        confirmText: target.getAttribute("data-confirm-ok") || "Confirmar",
        cancelText: target.getAttribute("data-confirm-cancel") || "Cancelar",
      }).then(function (confirmed) {
        target.removeAttribute("data-confirm-pending");
        if (!confirmed) {
          return;
        }

        target.setAttribute("data-confirm-approved", "1");
        if (typeof target.requestSubmit === "function") {
          target.requestSubmit();
          return;
        }

        target.submit();
      });
    });

    document.addEventListener("click", function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }

      var trigger = target.closest("a[data-confirm-message]");
      if (!trigger) {
        return;
      }

      var confirmMessage = trigger.getAttribute("data-confirm-message");
      if (!confirmMessage) {
        return;
      }

      event.preventDefault();
      if (trigger.getAttribute("data-confirm-pending") === "1") {
        return;
      }

      trigger.setAttribute("data-confirm-pending", "1");
      showSystemConfirm(confirmMessage, {
        title: trigger.getAttribute("data-confirm-title") || "Confirmação",
        confirmText: trigger.getAttribute("data-confirm-ok") || "Confirmar",
        cancelText: trigger.getAttribute("data-confirm-cancel") || "Cancelar",
      }).then(function (confirmed) {
        trigger.removeAttribute("data-confirm-pending");
        if (confirmed && trigger.href) {
          window.location.href = trigger.href;
        }
      });
    });
  }

  function getTablePageWindow(currentPage, totalPages) {
    if (totalPages <= 7) {
      var fullWindow = [];
      for (var i = 1; i <= totalPages; i += 1) {
        fullWindow.push(i);
      }
      return fullWindow;
    }

    var pages = [1];
    var start = Math.max(2, currentPage - 1);
    var end = Math.min(totalPages - 1, currentPage + 1);
    var n = 0;

    if (start > 2) {
      pages.push("...");
    }

    for (n = start; n <= end; n += 1) {
      pages.push(n);
    }

    if (end < totalPages - 1) {
      pages.push("...");
    }

    pages.push(totalPages);
    return pages;
  }

  function initTablePagination() {
    var PAGE_SIZE = 10;
    var tables = document.querySelectorAll("table.table");
    var tableIndex = 0;

    for (tableIndex = 0; tableIndex < tables.length; tableIndex += 1) {
      (function () {
        var table = tables[tableIndex];
        if (!table || table.getAttribute("data-pagination-server") === "1") {
          return;
        }

        var tbody = table.tBodies && table.tBodies.length > 0 ? table.tBodies[0] : null;
        if (!tbody) {
          return;
        }

        var allRows = [];
        var rowIndex = 0;
        for (rowIndex = 0; rowIndex < tbody.rows.length; rowIndex += 1) {
          allRows.push(tbody.rows[rowIndex]);
        }

        var dataRows = [];
        for (rowIndex = 0; rowIndex < allRows.length; rowIndex += 1) {
          var row = allRows[rowIndex];
          if (row.querySelector && row.querySelector("td.empty")) {
            row.style.display = "none";
            continue;
          }
          dataRows.push(row);
        }

        if (dataRows.length <= PAGE_SIZE) {
          return;
        }

        var pager = document.createElement("nav");
        pager.className = "pagination pagination-nav pagination-client js-table-pagination";
        pager.setAttribute("aria-label", "Paginação da tabela");

        if (table.nextSibling) {
          table.parentNode.insertBefore(pager, table.nextSibling);
        } else {
          table.parentNode.appendChild(pager);
        }

        var totalPages = Math.ceil(dataRows.length / PAGE_SIZE);
        var currentPage = 1;

        function createPagerButton(label, targetPage, isDisabled, extraClass, isCurrent) {
          var button = document.createElement("button");
          button.type = "button";
          button.className = "pagination-link" + (extraClass ? " " + extraClass : "");
          button.textContent = label;

          if (isCurrent) {
            addClass(button, "is-active");
            button.setAttribute("aria-current", "page");
          }

          if (isDisabled) {
            addClass(button, "is-disabled");
            button.disabled = true;
            return button;
          }

          button.setAttribute("data-page", String(targetPage));
          button.addEventListener("click", function () {
            currentPage = targetPage;
            renderPage();
          });

          return button;
        }

        function createEllipsis() {
          var span = document.createElement("span");
          span.className = "pagination-link pagination-ellipsis";
          span.textContent = "...";
          return span;
        }

        function renderPage() {
          var startRow = (currentPage - 1) * PAGE_SIZE;
          var endRow = startRow + PAGE_SIZE;
          var i = 0;

          for (i = 0; i < dataRows.length; i += 1) {
            dataRows[i].style.display = (i >= startRow && i < endRow) ? "" : "none";
          }

          pager.innerHTML = "";

          pager.appendChild(
            createPagerButton("\u00ab Anterior", currentPage - 1, currentPage <= 1, "pagination-control", false)
          );

          var windowPages = getTablePageWindow(currentPage, totalPages);
          for (i = 0; i < windowPages.length; i += 1) {
            var item = windowPages[i];
            if (item === "...") {
              pager.appendChild(createEllipsis());
            } else {
              var pageNumber = Number(item);
              pager.appendChild(
                createPagerButton(String(pageNumber), pageNumber, false, "", pageNumber === currentPage)
              );
            }
          }

          pager.appendChild(
            createPagerButton("Próximo \u00bb", currentPage + 1, currentPage >= totalPages, "pagination-control", false)
          );
        }

        renderPage();
      })();
    }
  }

  function initResponsiveTableLabels() {
    var tables = document.querySelectorAll("table.table");
    var tableIndex = 0;

    for (tableIndex = 0; tableIndex < tables.length; tableIndex += 1) {
      var table = tables[tableIndex];
      var headerCells = table.querySelectorAll("thead th");
      var labels = [];
      var i = 0;

      if (!headerCells || headerCells.length === 0) {
        continue;
      }

      for (i = 0; i < headerCells.length; i += 1) {
        labels.push(String(headerCells[i].textContent || "").trim());
      }

      var rows = table.querySelectorAll("tbody tr");
      var r = 0;
      for (r = 0; r < rows.length; r += 1) {
        var cells = rows[r].children;
        var columnIndex = 0;
        var c = 0;

        for (c = 0; c < cells.length; c += 1) {
          var cell = cells[c];
          if (!cell || cell.tagName !== "TD") {
            continue;
          }

          if (cell.hasAttribute("colspan")) {
            columnIndex += Number.parseInt(cell.getAttribute("colspan") || "1", 10) || 1;
            continue;
          }

          if (!cell.getAttribute("data-label")) {
            cell.setAttribute("data-label", labels[columnIndex] || "");
          }

          columnIndex += 1;
        }
      }
    }
  }

  function init() {
    initThemeToggle();
    initSidebarToggle();
    initMessagePopups();
    initGlobalActionConfirmations();
    initResponsiveTableLabels();
    initTablePagination();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

