const React = window.React;
const ReactDOM = window.ReactDOM;
const { createElement: h, useEffect, useMemo, useRef, useState } = React;
const root = ReactDOM.createRoot(document.getElementById("app"));
let deferredInstallPrompt = null;

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
  window.dispatchEvent(new Event("barangaycare:install-status"));
});

window.addEventListener("appinstalled", () => {
  deferredInstallPrompt = null;
  window.dispatchEvent(new Event("barangaycare:install-status"));
});

//#region Startup, session, and shared helpers
function createDefaultState() {
  return {
    ...window.BarangayCareService.seed,
    settings: {
      barangayName: "Barangay Taft",
      city: "Surigao City",
      province: "Surigao del Norte",
      region: "Caraga",
      address: "Barangay Hall, Taft, Surigao City",
      contact: "",
      email: "",
      officeHours: "Monday to Friday, 8:00 AM to 5:00 PM",
      punongBarangay: "",
    },
  };
}

function recordCount(data, key) {
  const value = data.meta?.[key];
  if (typeof value === "number") return value;
  return Array.isArray(data[key]) ? data[key].length : 0;
}

function placeName(settings) {
  const name = settings?.barangayName || "Barangay Taft";
  const city = settings?.city || "Surigao City";
  const province = settings?.province || "";
  return province ? `${name}, ${city}, ${province}` : `${name}, ${city}`;
}

function persistSession(account) {
  const session = {
    id: account.id,
    username: account.username,
    name: account.name,
    role: account.role,
    status: account.status,
    email: account.email || "",
    profileImage: account.profileImage || "",
    beneficiaryName: account.beneficiaryName || "",
    isBarangayCaptain: account.isBarangayCaptain === true,
    mustChangePassword: account.mustChangePassword === true,
  };
  window.sessionStorage.setItem("barangaycare.session", JSON.stringify(session));
  return session;
}

const roles = {
  user: {
    name: "Resident",
    accessLabel: "Resident",
    loginTitle: "Resident Login",
    theme: "#2563eb",
    subtitle: "Your registered benefits, assistance records, schedules, and barangay updates.",
    nav: ["Resident Dashboard", "My Assistance", "Upcoming Schedules", "Messages & Notifications", "My Reports / History", "Profile", "Settings", "Logout"],
  },
  admin: {
    name: "Admin",
    accessLabel: "Admin",
    loginTitle: "Admin Login",
    theme: "#16834a",
    subtitle: "Monitoring access for beneficiary services, assistance programs, registered accounts, and system activity.",
    nav: ["Dashboard Overview", "Beneficiary Management", "Scheduling and Distribution", "Registered Accounts", "Reports and Analytics", "Notifications", "Activity Monitor & Audit Logs", "Logout"],
  },
  secretary: {
    name: "Barangay Secretary",
    accessLabel: "Barangay Secretary",
    loginTitle: "Staff Login",
    theme: "#16834a",
    subtitle: "Manage resident records, create resident accounts, and coordinate schedules.",
    nav: ["Dashboard Overview", "Beneficiary Management", "Social Assistance", "Scheduling and Distribution", "Resident Accounts", "Reports and Analytics", "Profile", "Logout"],
  },
  distribution_staff: {
    name: "Distribution Staff",
    accessLabel: "Distribution Staff",
    loginTitle: "Staff Login",
    theme: "#16834a",
    subtitle: "Monitor schedules and record assistance claims.",
    nav: ["Dashboard Overview", "Scheduling and Distribution", "Social Assistance", "Resident Accounts", "Profile", "Logout"],
  },
  auditor: {
    name: "Read-only Auditor",
    accessLabel: "Read-only Auditor",
    loginTitle: "Auditor Login",
    theme: "#16834a",
    subtitle: "Review operational records and audit activity without editing data.",
    nav: ["Dashboard Overview", "Reports and Analytics", "Activity and Audit Logs", "Profile", "Logout"],
  },
  barangay: {
    name: "Barangay Captain",
    accessLabel: "Barangay Captain",
    loginTitle: "Barangay Login",
    theme: "#16834a",
    subtitle: "Oversee barangay operations and monitor staff and resident accounts.",
    nav: ["Dashboard", "Monitoring", "Staff and Resident Accounts", "Profile", "Logout"],
  },
};
const accountRoleOptions = ["User", "Barangay", "Admin", "Secretary", "Assistance Officer", "Distribution Staff", "Read-only Auditor"];

function accountRoleLabel(role) {
  const normalized = String(role || "").trim().toLowerCase();
  if (["admin", "super admin", "superadmin"].includes(normalized)) return "Admin";
  if (["barangay", "staff"].includes(normalized)) return "Barangay Captain";
  if (["secretary", "barangay secretary"].includes(normalized)) return "Secretary";
  if (["assistance_officer", "assistance officer"].includes(normalized)) return "Assistance Officer";
  if (["distribution_staff", "distribution staff"].includes(normalized)) return "Distribution Staff";
  if (["auditor", "read-only auditor", "read only auditor"].includes(normalized)) return "Read-only Auditor";
  if (["user", "resident", "resident account"].includes(normalized)) return "Resident";
  return role || "Unknown";
}

function accountRoleFormValue(role) {
  const label = accountRoleLabel(role);
  return label === "Resident" ? "User" : label;
}

const standardBenefitTypes = ["Senior Citizen", "PWD", "Solo Parent", "4Ps", "Pregnant Mother", "Indigent"];
const customAssistanceBenefitOption = "__custom_assistance_benefit__";
const programBenefitChoices = ["", "All residents", ...standardBenefitTypes, "Others"];
const programStatuses = ["Scheduled", "Open", "Completed"];
const beneficiaryCategories = [...standardBenefitTypes, "Other"];

function categoryList(value) {
  return String(value || "").split(",").map((part) => part.trim()).filter(Boolean);
}

function isStandardBenefit(type) {
  return standardBenefitTypes.some((item) => item.toLowerCase() === String(type).toLowerCase());
}

function knownProgramBenefit(value) {
  const benefit = String(value || "").trim();
  return ["All residents", ...standardBenefitTypes].find((item) => item.toLowerCase() === benefit.toLowerCase()) || "";
}

function hasCategory(value, type) {
  const items = categoryList(value);
  if (type === "Other") return items.some((item) => !isStandardBenefit(item));
  return items.some((item) => item.toLowerCase() === String(type).toLowerCase());
}

function readBenefitForm(value) {
  const items = categoryList(value);
  const standard = standardBenefitTypes.filter((type) => items.some((item) => item.toLowerCase() === type.toLowerCase()));
  const custom = items.filter((item) => !isStandardBenefit(item));
  return { standard, otherOpen: custom.length > 0, otherText: custom.join(", ") };
}

function specificBenefitNames(otherText) {
  const names = [];
  String(otherText || "").split(",").map((part) => part.trim()).filter(Boolean).forEach((name) => {
    if (isStandardBenefit(name) || names.some((item) => item.toLowerCase() === name.toLowerCase())) return;
    names.push(name);
  });
  return names;
}
function readSavedView() {
  const segments = window.location.pathname.split("/").filter(Boolean);
  const pathRole = segments[1];
  const role = pathRole === "resident" ? "user"
    : ["staff", "barangay"].includes(pathRole) ? "barangay"
      : ({ "distribution-staff": "distribution_staff" }[pathRole] || (["user", "admin", "secretary", "auditor"].includes(pathRole) ? pathRole : "user"));
  const storedSession = window.sessionStorage.getItem("barangaycare.session");
  let session = null;

  try {
    session = storedSession ? JSON.parse(storedSession) : null;
  } catch {
    window.sessionStorage.removeItem("barangaycare.session");
  }

  if (session && !window.sessionStorage.getItem("barangaycare.token")) {
    session = null;
    window.sessionStorage.removeItem("barangaycare.session");
  }

  if (segments[0] === "app") {
    const pageSlug = segments[2] || "";
    const pageAliases = {
      admin: {
        overview: "Dashboard Overview",
        "account-monitoring": "Registered Accounts",
        "registered-accounts": "Registered Accounts",
        "staff-management": "Registered Accounts",
        modules: "Dashboard Overview",
        "audit-logs": "Activity Monitor & Audit Logs",
        "activity-and-audit-logs": "Activity Monitor & Audit Logs",
        "activity-monitor": "Activity Monitor & Audit Logs",
      },
      barangay: { beneficiaries: "Beneficiaries", assistance: "Assistance", programs: "Programs and Schedule", users: "Staff and Resident Accounts", "resident-accounts": "Staff and Resident Accounts", "staff-and-resident-accounts": "Staff and Resident Accounts", settings: "Profile" },
      user: {
        "ai-message": "Messages & Notifications",
        "upcoming-schedule": "Upcoming Schedules",
        reports: "My Reports / History",
        "my-reports": "My Reports / History",
        "my-reports-history": "My Reports / History",
      },
    };
    const page = pageAliases[role]?.[pageSlug] || (roles[role]?.nav || []).filter((item) => item !== "Logout").find((item) => pageSlugFor(item) === pageSlug);
    if (session?.role === role) {
      const activePage = session.mustChangePassword ? "Profile" : page || roles[role].nav[0];
      return { screen: "app", session, selectedRole: role, loginRole: null, activePage };
    }
    return { screen: "login", session: null, selectedRole: role, loginRole: null, activePage: roles[role].nav[0] };
  }

  if (segments[0] === "login") {
    return { screen: "login", session: null, selectedRole: role, loginRole: segments[1] ? role : null, activePage: roles[role].nav[0] };
  }

  return { screen: "home", session: null, selectedRole: role, loginRole: null, activePage: roles[role].nav[0] };
}

function routePath(screen, role, page) {
  if (screen === "home") return "/";
  if (screen === "login") {
    if (!role) return "/login";
    const loginRole = role === "staff" ? "barangay"
      : role === "user" || role === "resident"
        ? "resident"
        : ({ distribution_staff: "distribution-staff" }[role] || role);
    return `/login/${loginRole}`;
  }
  const routeRole = {
    user: "resident",
    distribution_staff: "distribution-staff",
  }[role] || role;
  const pageSlug = routeRole === "resident"
    ? ({
      "Messages & Notifications": "ai-message",
      "Upcoming Schedules": "upcoming-schedule",
    }[page] || pageSlugFor(page))
    : role === "admin" && page === "Activity Monitor & Audit Logs"
      ? "activity-monitor"
      : pageSlugFor(page);
  return `/app/${routeRole}/${pageSlug}`;
}

function pageSlugFor(page) {
  return page.toLowerCase().replaceAll(" / ", "-").replaceAll(" ", "-");
}

function Icon({ id }) {
  return h("svg", { className: "icon", "aria-hidden": "true" }, h("use", { href: `#i-${id}` }));
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function startOfToday() {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

function statusClass(value) {
  const text = String(value).toLowerCase();
  if (text.includes("complete") || text.includes("active") || text.includes("open") || text.includes("claim") || text.includes("approv") || text.includes("distribut") || text.includes("verif")) return "ok";
  if (text.includes("pending") || text.includes("scheduled") || text.includes("review") || text.includes("submitted")) return "warn";
  return "danger";
}

function navIcon(item) {
  if (item === "Activity Monitor & Audit Logs") return "report";
  if (item.includes("Dashboard") || item.includes("Overview") || item.includes("Monitor")) return "dashboard";
  if (item.includes("Beneficiar") || item.includes("User") || item.includes("Resident") || item.includes("Staff") || item.includes("Account")) return "users";
  if (item.includes("Program") || item.includes("Schedule") || item.includes("Distribution")) return "calendar";
  if (item.includes("Assistance") || item.includes("Modules")) return "aid";
  if (item.includes("AI") || item.includes("Chat") || item.includes("Message") || item.includes("Notification")) return "message";
  if (item.includes("Report") || item.includes("Audit") || item.includes("Backup")) return "report";
  if (item.includes("Settings") || item.includes("Profile")) return "settings";
  return "logout";
}

const mobilePrimaryNav = {
  user: ["Resident Dashboard", "My Assistance", "Upcoming Schedules", "Messages & Notifications"],
  admin: ["Dashboard Overview", "Beneficiary Management", "Scheduling and Distribution"],
  barangay: ["Dashboard", "Monitoring", "Staff and Resident Accounts"],
  secretary: ["Dashboard Overview", "Beneficiary Management", "Social Assistance"],
  distribution_staff: ["Dashboard Overview", "Scheduling and Distribution", "Resident Accounts"],
  auditor: ["Dashboard Overview", "Reports and Analytics", "Activity and Audit Logs"],
};

function mobileNavLabel(item) {
  const labels = {
    "Resident Dashboard": "Home",
    "My Assistance": "Assistance",
    "Upcoming Schedule": "Schedule",
    "AI Message": "Messages",
    "Upcoming Schedules": "Schedule",
    "Messages & Notifications": "Messages",
    "My Reports / History": "History",
    Overview: "Home",
    "Dashboard Overview": "Home",
    "Beneficiary Management": "People",
    "Social Assistance": "Assistance",
    "Scheduling and Distribution": "Schedule",
    "Programs and Schedule": "Schedule",
    "Registered Accounts": "Accounts",
    "Staff and Resident Accounts": "Accounts",
    "Staff Management": "Accounts",
    "Resident Accounts": "Residents",
    "Staff Accounts": "Staff",
    "System Settings": "Settings",
    "Backup and Restore": "Backup",
    "Activity Monitor": "Activity",
    "Reports and Analytics": "Reports",
    "Activity and Audit Logs": "Audit",
    "Activity Monitor & Audit Logs": "Activity",
    "Account Monitoring": "Accounts",
    Modules: "Modules",
    "Audit Logs": "Logs",
    Dashboard: "Home",
    Beneficiaries: "People",
    "Beneficiaries & Assistance": "Records",
    Programs: "Programs",
    Assistance: "Aid",
  };
  return labels[item] || item;
}
//#endregion

//#region Application shell, authentication, and navigation
function App() {
  const savedView = useMemo(readSavedView, []);
  const [screen, setScreen] = useState(savedView.screen);
  const [selectedRole, setSelectedRole] = useState(savedView.selectedRole);
  const [loginRole, setLoginRole] = useState(savedView.loginRole);
  const [session, setSession] = useState(savedView.session);
  const [activePage, setActivePage] = useState(savedView.activePage);
  const [data, setData] = useState(createDefaultState());
  const [query, setQuery] = useState("");
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState("");
  const [navOpen, setNavOpen] = useState(false);
  const [loginError, setLoginError] = useState("");
  const [backendStatus, setBackendStatus] = useState({ ok: false, source: "checking", message: "Checking backend connection..." });
  const [dataVersion, setDataVersion] = useState("");
  const saveInProgress = useRef(false);
  const backendStatusRef = useRef(backendStatus);

  function updateBackendStatus(status) {
    backendStatusRef.current = status;
    setBackendStatus(status);
  }

  useEffect(() => {
    function closeDrawer(event) {
      if (event.key === "Escape") setNavOpen(false);
    }

    document.body.classList.toggle("nav-drawer-open", navOpen);
    window.addEventListener("keydown", closeDrawer);
    return () => {
      document.body.classList.remove("nav-drawer-open");
      window.removeEventListener("keydown", closeDrawer);
    };
  }, [navOpen]);
  useEffect(() => {
    const theme = session ? roles[session.role]?.theme : (screen === "login" ? "#16834a" : roles[selectedRole]?.theme);
    if (theme) document.documentElement.style.setProperty("--theme", theme);
  }, [session, screen, selectedRole]);

  function updateRoute(path, replace = false) {
    if (window.location.pathname !== path) {
      window.history[replace ? "replaceState" : "pushState"]({}, "", path);
    }
  }

  useEffect(() => {
    function restoreRoute() {
      const route = readSavedView();
      if (window.location.pathname.startsWith("/app/")) {
        updateRoute(route.screen === "app" ? routePath("app", route.selectedRole, route.activePage) : "/login", true);
      }
      setScreen(route.screen);
      setSelectedRole(route.selectedRole);
      setLoginRole(route.loginRole);
      setSession(route.session);
      setActivePage(route.activePage);
      if (route.session?.mustChangePassword && window.location.pathname.startsWith("/app/")) {
        updateRoute(routePath("app", route.selectedRole, "Profile"), true);
      }
      setQuery("");
      setModal(null);
      setNavOpen(false);
    }

    restoreRoute();
    window.addEventListener("popstate", restoreRoute);
    return () => window.removeEventListener("popstate", restoreRoute);
  }, []);

  useEffect(() => {
    window.BarangayCareService.health().then(updateBackendStatus);
    if (!session?.mustChangePassword) {
      window.BarangayCareService.loadData()
        .then((loaded) => setData({ ...createDefaultState(), ...loaded }))
        .catch((error) => {
          updateBackendStatus({ ok: false, source: "laravel-error", message: error.message });
          notify(`Could not load data from Laravel: ${error.message}`);
        });
    }
    window.BarangayCareService.version?.()
      .then((version) => setDataVersion(version || ""))
      .catch((error) => {
        updateBackendStatus({ ok: false, source: "laravel-error", message: error.message });
      });
  }, []);

  async function save(nextData) {
    if (saveInProgress.current) {
      notify("A Laravel save is already in progress. Please wait.");
      return false;
    }
    saveInProgress.current = true;
    try {
      const saved = await window.BarangayCareService.saveData(nextData);
      setData({ ...createDefaultState(), ...saved });
      window.BarangayCareService.version?.()
        .then((version) => setDataVersion(version || ""))
        .catch((error) => updateBackendStatus({ ok: false, source: "laravel-error", message: error.message }));
      window.BarangayCareService.health().then(updateBackendStatus);
      return true;
    } catch (error) {
      notify(`Could not save to Laravel: ${error.message}`);
      return false;
    } finally {
      saveInProgress.current = false;
    }
  }

  async function refreshData() {
    const loaded = await window.BarangayCareService.loadData();
    setData({ ...createDefaultState(), ...loaded });
    const version = await window.BarangayCareService.version?.();
    if (version) setDataVersion(version);
  }

  useEffect(() => {
    if (!session || session.mustChangePassword || modal) return undefined;

    let active = true;
    const refreshIfChanged = async () => {
      try {
        const latestVersion = await window.BarangayCareService.version?.();
        if (!active || !latestVersion) return;
        if (dataVersion && latestVersion !== dataVersion) {
          const loaded = await window.BarangayCareService.loadData();
          if (active) setData({ ...createDefaultState(), ...loaded });
        }
        if (active) setDataVersion(latestVersion);
        if (active && !backendStatusRef.current.ok) {
          const connection = await window.BarangayCareService.health();
          if (active) updateBackendStatus(connection);
        }
      } catch (error) {
        if (active) updateBackendStatus({ ok: false, source: "laravel-error", message: error.message });
      }
    };

    const timer = window.setInterval(refreshIfChanged, 5000);
    refreshIfChanged();
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [session, modal, dataVersion]);

  function notify(message) {
    setToast(message);
    window.clearTimeout(window.barangayCareToast);
    window.barangayCareToast = window.setTimeout(() => setToast(""), 2600);
  }

  function logAction(actor, action, base = data) {
    return {
      ...base,
      logs: [{ time: new Date().toLocaleString("en-PH", { hour12: false }), actor, action }, ...base.logs].slice(0, 40),
    };
  }

  function navigate(page) {
    if (session.mustChangePassword && page !== "Profile" && page !== "Logout") {
      notify("Change your temporary password before continuing.");
      return;
    }

    setActivePage(page);
    setQuery("");
    setNavOpen(false);
    updateRoute(routePath("app", session.role, page));
  }

  function openLogin(role = null) {
    setLoginError("");
    setLoginRole(role);
    if (role) setSelectedRole(role);
    setScreen("login");
    updateRoute(routePath("login", role));
  }

  function returnHome() {
    setScreen("home");
    setSelectedRole("user");
    setLoginRole(null);
    setLoginError("");
    updateRoute("/");
  }

  async function login(username, password) {
    try {
      const result = await window.BarangayCareService.signIn(username, password);
      const account = result.user;
      const authenticatedRole = account.role;
      if (authenticatedRole === "assistance_officer") {
        throw new Error("The Assistance Officer workspace is no longer available. Contact the Barangay Captain for assistance.");
      }
      if (!roles[authenticatedRole]) {
        throw new Error("Laravel returned an unsupported account role.");
      }
      window.sessionStorage.setItem("barangaycare.token", result.token);
      const refreshSession = persistSession(account);
      setLoginError("");
      setSelectedRole(authenticatedRole);
      setSession(refreshSession);
      const initialPage = account.mustChangePassword
        ? "Profile"
        : authenticatedRole === "user" ? "Resident Dashboard" : roles[authenticatedRole].nav[0];
      setActivePage(initialPage);
      setScreen("app");
      updateRoute(routePath("app", authenticatedRole, initialPage));
      if (!account.mustChangePassword) {
        try {
          await refreshData();
        } catch (error) {
          notify(`Signed in, but records could not be refreshed: ${error.message}`);
        }
      }
      notify(account.mustChangePassword
        ? "Your temporary password must be changed before continuing."
        : `Signed in as ${roles[authenticatedRole].name}`);
    } catch (error) {
      setLoginError(error.message);
    }
  }

  function actionsLog(actor, action, base) {
    return {
      ...base,
      logs: [{ time: new Date().toLocaleString("en-PH", { hour12: false }), actor, action }, ...base.logs].slice(0, 40),
    };
  }

  async function logout() {
    await window.BarangayCareService.signOut();
    window.sessionStorage.removeItem("barangaycare.session");
    setSession(null);
    setScreen("home");
    setSelectedRole("user");
    setActivePage("Dashboard");
    setQuery("");
    updateRoute("/");
    notify("Signed out successfully");
  }

  async function notifyAssistance(record, eventType) {
    const result = await window.BarangayCareService.notifyAssistance(record, eventType);
    if (result.ok) {
      notify(result.message || `Email sent to the resident Gmail ${result.sentTo}.`);
    } else if (!result.skipped) {
      notify(`Record saved. Email not sent: ${result.error}`);
    }
    return result;
  }

  const actions = { data, save, refreshData, logAction, session, setSession, setModal, query, setQuery, notify, navigate, backendStatus, notifyAssistance };

  if (!session && screen === "home") {
    return h(Landing, {
      data,
      openLogin,
    });
  }

  if (!session) return h(Login, { onLogin: login, loginError, loginRole, backHome: returnHome });

  return h(
    "div",
    { className: `app-shell ${navOpen ? "nav-open" : ""}` },
    h("button", { className: "mobile-menu no-print", onClick: () => setNavOpen(!navOpen), "aria-label": "Toggle navigation menu", "aria-expanded": navOpen, "aria-controls": "app-sidebar" }, navOpen ? "Close" : "Menu"),
    navOpen && h("button", { className: "mobile-scrim no-print", onClick: () => setNavOpen(false), "aria-label": "Close navigation menu" }),
    h(Sidebar, { session, activePage, setActivePage: navigate, logout }),
    h(MobileNav, { session, activePage, navigate, navOpen, setNavOpen }),
    h("main", { className: "main" }, h(Topbar, { session, activePage, backendStatus, settings: data.settings }), h(Route, { activePage, actions }), modal && h(Modal, { modal, actions, close: () => setModal(null) })),
    toast && h("div", { className: "toast", role: "status" }, toast)
  );
}

function Landing({ data, openLogin }) {
  const place = `${data.settings?.barangayName || "Barangay Taft"} · ${data.settings?.city || "Surigao City"}`;
  return h(
    "main",
    { className: "landing-shell" },
    h(
      "section",
      { className: "landing-hero" },
      h(
        "div",
        { className: "landing-nav" },
        h(Brand)
      ),
      h(
        "div",
        { className: "landing-content" },
        h(
          "div",
          { className: "landing-copy" },
          h("span", { className: "eyebrow" }, place),
          h("h1", null, h("span", null, "Care for our"), h("span", { className: "landing-title-accent" }, "community.")),
          h("p", null, "A simpler way for our community to connect residents with assistance, programs, and the support they need."),
          h(
            "div",
            { className: "landing-actions" },
            h("button", { className: "primary landing-cta", onClick: () => openLogin() }, "Continue to sign in", h("span", { "aria-hidden": "true" }, "→")),
            h(InstallAppAction)
          )
        )
      )
    )
  );
}

function InstallAppAction() {
  const [installable, setInstallable] = useState(() => Boolean(deferredInstallPrompt));
  const [installed, setInstalled] = useState(() => window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true);
  const [instructionsOpen, setInstructionsOpen] = useState(false);
  const [installError, setInstallError] = useState("");
  const isAppleMobile = /iPhone|iPad|iPod/i.test(window.navigator.userAgent)
    || (window.navigator.platform === "MacIntel" && window.navigator.maxTouchPoints > 1);

  useEffect(() => {
    function syncInstallStatus() {
      setInstallable(Boolean(deferredInstallPrompt));
      setInstalled(window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true);
    }

    window.addEventListener("barangaycare:install-status", syncInstallStatus);
    return () => window.removeEventListener("barangaycare:install-status", syncInstallStatus);
  }, []);

  async function installApp() {
    const prompt = deferredInstallPrompt;
    if (!prompt) {
      setInstructionsOpen(true);
      return;
    }

    try {
      await prompt.prompt();
      const choice = await prompt.userChoice;
      if (choice.outcome === "accepted") setInstalled(true);
      else setInstructionsOpen(true);
    } catch (error) {
      console.error("BarangayCare installation prompt could not be opened.", error);
      setInstallError("The install prompt could not be opened. Use your browser menu to install or add BarangayCare to your Home Screen.");
      setInstructionsOpen(true);
    } finally {
      deferredInstallPrompt = null;
      setInstallable(false);
    }
  }

  return h("div", { className: "landing-install-group" },
    installed
      ? h("p", { className: "landing-install-note", role: "status" }, "BarangayCare is installed on this device.")
      : (installable || isAppleMobile) && h("button", {
        className: "landing-install",
        type: "button",
        onClick: installable ? installApp : () => setInstructionsOpen((open) => !open),
        "aria-expanded": instructionsOpen,
      }, installable ? "Install BarangayCare" : "Add BarangayCare to Home Screen"),
    instructionsOpen && !installed && h("p", { className: "landing-install-help", role: "status" },
      installError || (isAppleMobile
        ? "In Safari, tap Share, then choose Add to Home Screen."
        : "Open your browser menu and choose Install app or Add to Home Screen.")
    ),
    h("p", { className: "landing-install-note" }, "Connect to the BarangayCare server to sign in, load records, and save changes. At the barangay hall, the same Wi-Fi is enough; public internet is not required.")
  );
}

function Login({ onLogin, loginError, loginRole, backHome }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [resetLink, setResetLink] = useState(() => {
    const parameters = new URLSearchParams(window.location.hash.slice(1));
    const token = parameters.get("password-reset") || "";
    const userId = parameters.get("user-id") || "";
    if (token) {
      window.history.replaceState({}, "", window.location.pathname + window.location.search);
    }
    return { token, userId };
  });
  const [mode, setMode] = useState(resetLink.token ? "reset-password" : "sign-in");
  const [notice, setNotice] = useState("");
  const [forgotError, setForgotError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [forgotForm, setForgotForm] = useState({ username: "", email: "" });
  const [resetForm, setResetForm] = useState({ password: "", confirmation: "" });
  function openForgot() {
    setForgotForm({ username, email: "" });
    setForgotError("");
    setNotice("");
    setMode("forgot");
  }

  async function submitForgot(event) {
    event.preventDefault();
    setSubmitting(true);
    setForgotError("");
    try {
      const result = await window.BarangayCareService.requestPasswordReset(
        forgotForm.username,
        forgotForm.email
      );
      setNotice(result.message || "If an active account matches those details, a password reset link will be sent to its registered email address.");
      setMode("sign-in");
    } catch (error) {
      setForgotError(error.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function submitResetPassword(event) {
    event.preventDefault();
    if (resetForm.password !== resetForm.confirmation) {
      setForgotError("New password and confirmation do not match.");
      return;
    }

    setSubmitting(true);
    setForgotError("");
    try {
      const result = await window.BarangayCareService.resetForgottenPassword(
        resetLink.userId,
        resetLink.token,
        resetForm.password,
        resetForm.confirmation
      );
      setResetForm({ password: "", confirmation: "" });
      setResetLink({ token: "", userId: "" });
      setNotice(result.message || "Password updated. Sign in with your new password.");
      setMode("sign-in");
    } catch (error) {
      setForgotError(error.message);
    } finally {
      setSubmitting(false);
    }
  }

  const signInForm = h(
    React.Fragment,
    null,
    notice && h("div", { className: "notice success auth-notice", role: "status" }, notice),
    loginError && h("div", { className: "alert" }, loginError),
    h("div", { className: "auth-route-note" },
      h("span", { className: "auth-route-indicator", "aria-hidden": "true" }, h(Icon, { id: "lock" })),
      h("span", null, "Your account role securely routes you to the right workspace after sign-in. Resident accounts are created by the Barangay Secretary; self-registration is not available.")
    ),
    h("form", { onSubmit: async (event) => {
      event.preventDefault();
      if (submitting) return;
      setSubmitting(true);
      try {
        await onLogin(username, password);
      } finally {
        setSubmitting(false);
      }
    } },
      h(Field, { label: "Username", value: username, onChange: setUsername, autoComplete: "username" }),
      h(Field, { label: "Password", type: "password", value: password, onChange: setPassword, autoComplete: "current-password" }),
      h("div", { className: "forgot-row" }, h("button", { type: "button", onClick: openForgot }, "Forgot password?")),
      h("button", { className: "primary auth-submit", type: "submit", disabled: submitting }, h(Icon, { id: "lock" }), submitting ? " Signing in..." : " Sign In")
    ),
  );

  const forgotFormView = h(
    React.Fragment,
    null,
    h("h1", { className: "auth-title" }, "Forgot password"),
    h("p", { className: "auth-copy" }, "Enter the username and email registered to your account. We will email you a secure, one-time password reset link."),
    forgotError && h("div", { className: "alert" }, forgotError),
    h("form", { onSubmit: submitForgot },
      h(Field, { label: "Username", value: forgotForm.username, onChange: (value) => setForgotForm((current) => ({ ...current, username: value })), autoComplete: "username" }),
      h(Field, { label: "Email on the account", type: "email", value: forgotForm.email, onChange: (value) => setForgotForm((current) => ({ ...current, email: value })), autoComplete: "email" }),
      h("button", { className: "primary auth-submit", type: "submit", disabled: submitting }, submitting ? "Sending..." : "Email reset link")
    ),
    h("p", { className: "auth-switch" }, h("button", { type: "button", onClick: () => setMode("sign-in") }, "Back to sign in"))
  );

  const resetPasswordFormView = h(
    React.Fragment,
    null,
    h("h1", { className: "auth-title" }, "Choose a new password"),
    h("p", { className: "auth-copy" }, "This secure reset link is for your BarangayCare account and expires after 60 minutes."),
    forgotError && h("div", { className: "alert" }, forgotError),
    h("form", { onSubmit: submitResetPassword },
      h(Field, { label: "New password (at least 8 characters)", type: "password", value: resetForm.password, onChange: (value) => setResetForm((current) => ({ ...current, password: value })), autoComplete: "new-password", minLength: 8 }),
      h(Field, { label: "Confirm new password", type: "password", value: resetForm.confirmation, onChange: (value) => setResetForm((current) => ({ ...current, confirmation: value })), autoComplete: "new-password", minLength: 8 }),
      h("button", { className: "primary auth-submit", type: "submit", disabled: submitting }, submitting ? "Updating..." : "Reset password")
    ),
    h("p", { className: "auth-switch" }, h("button", { type: "button", onClick: () => setMode("sign-in") }, "Back to sign in"))
  );

  return h(
    "main",
    { className: "auth-shell login-shell" },
    h(
      "section",
      { className: "auth-panel" },
      h("div", { className: "login-column" },
      h("button", { className: "back-link", onClick: backHome, type: "button" }, h("span", { "aria-hidden": "true" }, "←"), " Back to home"),
      h("div", { className: "auth-card" },
        h(Brand),
        h("span", { className: "auth-eyebrow" }, "RESIDENT & BARANGAY SERVICES"),
        mode === "sign-in" && h("h1", { className: "auth-title" }, "Welcome back"),
        mode === "sign-in" && h("p", { className: "auth-copy" }, "Sign in to continue to your BarangayCare account."),
        mode === "forgot" ? forgotFormView : mode === "reset-password" ? resetPasswordFormView : signInForm
      )
      )
    ),
    h("section", { className: "login-visual", "aria-label": "BarangayCare services" },
      h("div", { className: "login-visual-orb login-visual-orb-one", "aria-hidden": "true" }),
      h("div", { className: "login-visual-orb login-visual-orb-two", "aria-hidden": "true" }),
      h("div", { className: "login-visual-content" },
        h("div", { className: "login-visual-brand" },
          h("span", { className: "login-visual-brand-mark", "aria-hidden": "true" }, h(Icon, { id: "aid" })),
          h("span", null, "BarangayCare")
        ),
        h("span", { className: "login-visual-kicker" }, "COMMUNITY SERVICES, MADE CLEAR"),
        h("h2", null, "Support for your community, in one place."),
        h("p", { className: "login-visual-copy" }, "A secure portal for resident assistance, barangay programs, and day-to-day service coordination."),
        h("div", { className: "login-service-list" },
          h("div", { className: "login-service-item" },
            h("span", { className: "login-service-icon", "aria-hidden": "true" }, h(Icon, { id: "aid" })),
            h("span", null, h("b", null, "Assistance tracking"), h("small", null, "View assistance recorded by barangay staff"))
          ),
          h("div", { className: "login-service-item" },
            h("span", { className: "login-service-icon", "aria-hidden": "true" }, h(Icon, { id: "calendar" })),
            h("span", null, h("b", null, "Program schedules"), h("small", null, "See upcoming activities and distribution details"))
          ),
          h("div", { className: "login-service-item" },
            h("span", { className: "login-service-icon", "aria-hidden": "true" }, h(Icon, { id: "message" })),
            h("span", null, h("b", null, "Direct updates"), h("small", null, "Keep important messages in one place"))
          )
        ),
        h("div", { className: "login-visual-footer" }, h(Icon, { id: "lock" }), h("span", null, "Your access is based on your assigned account role."))
      )
    )
  );
}

function Brand() {
  return h(
    "div",
    { className: "brand" },
    h(
      "div",
      { className: "brand-mark", "aria-hidden": "true" },
      h("svg", { viewBox: "0 0 64 64" }, h("path", { d: "M12 29 32 13l20 16v22a4 4 0 0 1-4 4H16a4 4 0 0 1-4-4V29Z", className: "logo-home" }), h("path", { d: "M26 34h12M32 28v12", className: "logo-cross" }), h("circle", { cx: "22", cy: "47", r: "4", className: "logo-person" }), h("circle", { cx: "42", cy: "47", r: "4", className: "logo-person" }))
    ),
    h("span", null, "BarangayCare")
  );
}

function Field({ label, value, onChange, type = "text", autoComplete, minLength, required = true, placeholder }) {
  const inputProps = { className: "input", value, placeholder, onChange: (event) => onChange(event.target.value), autoComplete, minLength, required };
  return h("label", { className: "field" }, h("span", null, label), type === "password" ? h(PasswordInput, inputProps) : h("input", { ...inputProps, type }));
}

function PasswordInput({ className = "input", ...inputProps }) {
  const [visible, setVisible] = useState(false);
  return h("div", { className: "password-input-wrap" },
    h("input", { ...inputProps, className, type: visible ? "text" : "password" }),
    h("button", {
      className: "password-visibility-toggle",
      type: "button",
      onClick: () => setVisible((current) => !current),
      "aria-label": visible ? "Hide password" : "Show password",
      "aria-pressed": visible,
      title: visible ? "Hide password" : "Show password",
    }, h("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2", strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": "true" },
      h("path", { d: visible ? "M3 3l18 18" : "M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" }),
      h("circle", { cx: "12", cy: "12", r: "3", style: visible ? { display: "none" } : undefined }),
      visible && h("path", { d: "M9.9 9.9a3 3 0 0 0 4.2 4.2M6.2 6.2C3.7 7.8 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.5 4.5-1.2" })
    ))
  );
}

function MiniCard({ value, label }) {
  return h("div", { className: "mini-card" }, h("b", null, value), h("span", null, label));
}

function Sidebar({ session, activePage, setActivePage, logout }) {
  const nav = session.mustChangePassword ? ["Profile", "Logout"] : roles[session.role].nav;
  const systemPages = ["Activity and Audit Logs", "Notifications", "System Settings", "Backup and Restore", "Activity Monitor", "Activity Monitor & Audit Logs"];
  const workspaceItems = nav.filter((item) => ![...systemPages, "Logout"].includes(item));
  const systemItems = nav.filter((item) => systemPages.includes(item));
  const sections = session.mustChangePassword
    ? [{ label: "ACCOUNT", items: ["Profile"] }]
    : session.role === "barangay"
    ? [
      { label: "MONITORING", items: ["Dashboard", "Monitoring", "Staff and Resident Accounts"] },
      { label: "ACCOUNT", items: ["Profile"] },
    ]
    : null;
  const renderItem = (item) => h("button", {
    key: item,
    className: item === activePage || (item === "Beneficiaries & Assistance" && ["Beneficiaries", "Assistance"].includes(activePage)) ? "active" : "",
    onClick: () => (item === "Logout" ? logout() : setActivePage(item)),
  }, h(Icon, { id: navIcon(item) }), h("span", null, item));

  return h("aside", { className: "sidebar", id: "app-sidebar" },
    h(Brand),
    session.role === "admin" && h("div", { className: "sidebar-caption" }, "ADMIN PANEL"),
    h("nav", { className: "nav", "aria-label": "Main navigation" },
      sections
        ? sections.map((section) => h("div", { className: "nav-section", key: section.label }, h("span", { className: "nav-section-label" }, section.label), section.items.filter((item) => nav.includes(item)).map(renderItem)))
        : h(React.Fragment, null,
          h("div", { className: "nav-section" }, h("span", { className: "nav-section-label" }, session.role === "admin" ? "MAIN MENU" : "WORKSPACE"), workspaceItems.map(renderItem)),
          systemItems.length > 0 && h("div", { className: "nav-section" }, h("span", { className: "nav-section-label" }, "SYSTEM MANAGEMENT"), systemItems.map(renderItem))
        ),
    ),
    h("div", { className: "logout-nav" }, renderItem("Logout"))
  );
}

function MobileNav({ session, activePage, navigate, navOpen, setNavOpen }) {
  const items = session.mustChangePassword ? ["Profile"] : mobilePrimaryNav[session.role] || [];
  const moreActive = navOpen || !items.includes(activePage);
  return h(
    "nav",
    {
      className: "mobile-nav no-print",
      "aria-label": "Phone pages",
      style: { gridTemplateColumns: `repeat(${items.length + 1}, minmax(0, 1fr))` },
    },
    items.map((item) => h("button", { key: item, type: "button", "aria-label": item, className: (item === activePage || (item === "Beneficiaries & Assistance" && ["Beneficiaries", "Assistance"].includes(activePage))) && !navOpen ? "active" : "", onClick: () => navigate(item) }, h(Icon, { id: navIcon(item) }), h("span", null, mobileNavLabel(item)))),
    h("button", { type: "button", "aria-label": navOpen ? "Close more pages" : "Open more pages", className: moreActive ? "active" : "", onClick: () => setNavOpen(!navOpen), "aria-expanded": navOpen, "aria-controls": "app-sidebar" }, h(Icon, { id: "settings" }), h("span", null, navOpen ? "Close" : "More"))
  );
}

function Topbar({ session, activePage, backendStatus, settings }) {
  const statusLabel = backendStatus.source === "checking" ? "Checking Backend" : backendStatus.ok ? backendStatus.source === "laravel" || backendStatus.source === "laravel-mysql" ? "Laravel Connected" : "Backend Connected" : "Laravel Offline";
  const pageTitle = activePage;
  return h("div", { className: "topbar" }, h("div", null, h("h1", null, pageTitle), h("p", null, `${roles[session.role].name} workspace for ${placeName(settings)}`)), h("div", { className: "toolbar" }, h(UserChip, { session }), h("span", { className: `system-pill ${backendStatus.ok ? "ok" : "warn"}`, title: backendStatus.message }, statusLabel), h("button", { className: "secondary", onClick: () => window.print() }, h(Icon, { id: "report" }), " Print")));
}

function UserChip({ session }) {
  const initials = (session.name || session.username || "BC").split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase();
  return h("div", { className: "user-chip" },
    h("div", { className: `user-chip-avatar ${session.profileImage ? "has-photo" : ""}` }, session.profileImage ? h("img", { src: session.profileImage, alt: "" }) : initials),
    h("span", null, session.name || session.username)
  );
}
//#endregion

//#region Role routing and resident-facing pages
function Route({ activePage, actions }) {
  const role = actions.session.role;
  if (role === "user") {
    if (activePage === "My Assistance") return h(ResidentAssistance, { actions });
    if (activePage === "Upcoming Schedule" || activePage === "Upcoming Schedules") return h(ResidentSchedules, { actions });
    if (activePage === "AI Message" || activePage === "Messages & Notifications") return h(ResidentMessageDesk, { actions });
    if (activePage === "Reports" || activePage === "My Reports / History") return h(ResidentHistory, { actions });
    if (activePage === "Profile") return h(Profile, { actions });
    if (activePage === "Settings") return h(Settings, { actions });
    return h(UserDashboard, { actions });
  }
  if (role === "admin") {
    if (activePage === "Registered Accounts" || activePage === "Staff Management" || activePage === "Account Monitoring") return h(Users, { actions });
    if (activePage === "Beneficiary Management") return h(Beneficiaries, { actions, editable: false });
    if (activePage === "Scheduling and Distribution") return h(Programs, { actions, editable: false });
    if (activePage === "Reports and Analytics") return h(Reports, { actions });
    if (activePage === "Notifications") return h(AdminNotifications, { actions });
    if (activePage === "System Settings") return h(Settings, { actions, readOnly: true });
    if (activePage === "Modules") return h(Modules, { actions });
    if (activePage === "Activity Monitor & Audit Logs" || activePage === "Activity and Audit Logs" || activePage === "Audit Logs") return h(Monitoring, { actions, includeAuditLogs: true });
    if (activePage === "Backup and Restore") return h(Backup, { actions });
    if (activePage === "Activity Monitor") return h(Monitoring, { actions, includeAuditLogs: true });
    return h(AdminDashboard, { actions });
  }
  if (role === "secretary") {
    if (activePage === "Beneficiary Management") return h(Beneficiaries, { actions, editable: true });
    if (activePage === "Social Assistance") return h(Assistance, { actions, editable: true });
    if (activePage === "Scheduling and Distribution") return h(Programs, { actions, editable: true });
    if (activePage === "Resident Accounts") return h(Users, { actions });
    if (activePage === "Reports and Analytics") return h(Reports, { actions });
    if (activePage === "Profile") return h(Profile, { actions });
    return h(AdminDashboard, { actions });
  }
  if (role === "distribution_staff") {
    if (activePage === "Scheduling and Distribution") return h(Programs, { actions, editable: false });
    if (activePage === "Social Assistance") return h(Assistance, { actions, editable: false });
    if (activePage === "Resident Accounts") return h(Users, { actions });
    if (activePage === "Profile") return h(Profile, { actions });
    return h(AdminDashboard, { actions });
  }
  if (role === "auditor") {
    if (activePage === "Reports and Analytics") return h(Reports, { actions });
    if (activePage === "Activity and Audit Logs") return h(Logs, { logs: actions.data.logs });
    if (activePage === "Profile") return h(Profile, { actions });
    return h(AdminDashboard, { actions });
  }
  if (role === "barangay") {
    if (activePage === "Staff and Resident Accounts" || activePage === "Users" || activePage === "Resident Accounts") return h(Users, { actions });
    if (activePage === "Monitoring") return h(CaptainMonitoring, { actions });
    if (activePage === "Profile") return h(Profile, { actions });
    return h(BarangayDashboard, { actions });
  }
  if (activePage === "Beneficiaries & Assistance" || ["Beneficiaries", "Assistance"].includes(activePage)) return h(StaffRecords, { actions, activePage });
  if (activePage === "Programs" || activePage === "Programs and Schedule") return h(Programs, { actions });
  if (activePage === "Monitoring") return h(Monitoring, { actions });
  if (activePage === "Users" || activePage === "Resident Accounts" || activePage === "Staff and Resident Accounts") return h(Users, { actions });
  if (activePage === "Reports") return h(Reports, { actions });
  if (activePage === "Profile") return h(Profile, { actions });
  return h(BarangayDashboard, { actions });
}

function Stats({ data, showBeneficiaries = true }) {
  const completed = data.assistance.filter((a) => a.status === "Claimed").length;
  const pending = data.assistance.filter((a) => a.status === "Pending").length;
  const upcoming = data.programs.filter((p) => new Date(p.date) >= startOfToday()).length;
  return h("section", { className: "stats" }, showBeneficiaries && h(Stat, { title: "Registered Beneficiaries", value: data.beneficiaries.length, note: "Real-time profile records" }), h(Stat, { title: "Assistance received", value: completed, note: "Confirmed by distribution staff" }), h(Stat, { title: "Pending Requests", value: pending, note: "For validation or release" }), h(Stat, { title: "Upcoming Schedules", value: upcoming, note: "Programs and activities" }));
}

function Stat({ title, value, note, className = "" }) {
  return h("div", { className: `card ${className}`.trim() }, h("div", { className: "stat-title" }, title), h("div", { className: "stat-value" }, value), h("div", { className: "stat-note" }, note));
}

function DistributionPageIntro({ icon, section, title, description, detail, className = "" }) {
  return h("section", { className: `distribution-page-intro ${className}`.trim() },
    h("span", { className: "distribution-page-intro-icon", "aria-hidden": "true" }, h(Icon, { id: icon })),
    h("div", { className: "distribution-page-intro-copy" },
      h("span", { className: "distribution-page-intro-eyebrow" }, `DISTRIBUTION STAFF · ${section.toUpperCase()}`),
      h("h2", null, title),
      h("p", null, description)
    ),
    h("div", { className: "distribution-page-intro-context" },
      h("span", null, "STAFF WORKSPACE"),
      h("b", null, detail),
      h("small", null, "Available actions follow your assigned permissions.")
    )
  );
}

function currentAccount(actions) {
  return actions.data.users.find((user) => user.username === actions.session.username) || actions.session;
}

function scopedAssistance(actions) {
  if (actions.session.role !== "user") return actions.data.assistance;
  const account = currentAccount(actions);
  const beneficiaryName = String(account.beneficiaryName || account.name || actions.session.name || "").trim().toLowerCase();
  return actions.data.assistance
    .filter((item) => String(item.beneficiary || "").trim().toLowerCase() === beneficiaryName)
    .filter((item) => String(item.amount || "").trim().toLowerCase() !== "as released");
}

function scopedData(actions) {
  return { ...actions.data, assistance: scopedAssistance(actions), beneficiaries: actions.session.role === "user" ? actions.data.beneficiaries.filter((b) => b.name.toLowerCase() === (currentAccount(actions).beneficiaryName || currentAccount(actions).name || "").toLowerCase()) : actions.data.beneficiaries };
}

function residentUpcomingPrograms(actions) {
  const account = currentAccount(actions);
  const beneficiaryName = String(account.beneficiaryName || account.name || actions.session.name || "").trim().toLowerCase();
  const beneficiary = actions.data.beneficiaries.find((item) => String(item.name || "").trim().toLowerCase() === beneficiaryName);
  const benefits = categoryList(beneficiary?.category);
  scopedAssistance(actions).forEach((item) => {
    const type = String(item.type || "").trim();
    if (type && !benefits.some((name) => name.toLowerCase() === type.toLowerCase())) benefits.push(type);
  });
  const todayKey = localDateKey();
  return (actions.data.programs || [])
    .filter((item) => {
      if (String(item.status || "").toLowerCase() === "completed") return false;
      const day = String(item.date || "").slice(0, 10);
      if (!day || day < todayKey) return false;
      const benefit = String(item.benefit || "").trim().toLowerCase();
      if (!benefit) return false;
      if (benefit === "all residents") return true;
      return benefits.some((type) => type.toLowerCase() === benefit);
    })
    .sort((a, b) => `${a.date} ${a.time || ""}`.localeCompare(`${b.date} ${b.time || ""}`));
}

function residentProgramAllocation(program, actions) {
  const benefit = String(program.benefit || "").trim().toLowerCase();
  const assignedRecord = benefit && benefit !== "all residents"
    ? scopedAssistance(actions).find((record) =>
      String(record.type || "").trim().toLowerCase() === benefit
      && ["Pending", "Completed"].includes(record.status)
      && String(record.amount || "").trim()
      && String(record.amount || "").trim().toLowerCase() !== "as released"
    )
    : null;
  const amount = String(assignedRecord?.amount || program.releaseAmount || "").trim();

  return {
    amount,
    isResidentSpecific: Boolean(assignedRecord),
  };
}

function ResidentSchedules({ actions }) {
  const programs = residentUpcomingPrograms(actions);
  return h("section", { className: "grid resident-schedules" },
    h("div", { className: "notice" }, "These upcoming schedules are connected to your resident account."),
    h(AssistanceReleaseSchedule, { actions, mode: "resident" }),
    h("section", { className: "card resident-schedule-list" },
      h("div", { className: "section-title" },
        h("div", null, h("h2", null, "Your upcoming program schedules"), h("p", { className: "section-note" }, "Only programs that match your registered benefits are shown.")),
        h("span", { className: "badge" }, `${programs.length} ${programs.length === 1 ? "schedule" : "schedules"}`)
      ),
      programs.length
        ? h("div", { className: "list" }, programs.map((program) => {
          const allocation = residentProgramAllocation(program, actions);

          return h("article", { className: "list-item resident-schedule-card", key: program.id },
            h("div", null, h("b", null, program.name), badge(program.status || "Scheduled")),
            h("span", { className: "muted" }, `${program.benefit || "Benefit not set"} · ${scheduleDetails(program)}`),
            allocation.amount && h("span", null, h("b", null, allocation.isResidentSpecific ? "Planned amount / items for you: " : "Planned amount / items: "), allocation.amount),
            allocation.amount && h("small", { className: "muted" }, "Final amount or items will be confirmed when distribution staff records your handover."),
            program.instructions && h("span", null, h("b", null, "Instructions: "), program.instructions)
          );
        }))
        : h("div", { className: "empty compact" }, "There are no upcoming schedules matching your registered benefits.")
    )
  );
}

function AssistanceReleaseSchedule({ actions, mode }) {
  const isResident = mode === "resident";
  const isSecretary = mode === "secretary";
  const records = (isResident ? scopedAssistance(actions) : actions.data.assistance || [])
    .filter((record) => ["Pending", "Completed"].includes(record.status));
  const todayKey = localDateKey();
  const groups = Array.from(records.reduce((byDate, record) => {
    const dateKey = String(record.date || "").slice(0, 10) || "unscheduled";
    const group = byDate.get(dateKey) || [];
    group.push(record);
    byDate.set(dateKey, group);
    return byDate;
  }, new Map()).entries())
    .sort(([firstDate], [secondDate]) => firstDate.localeCompare(secondDate));

  return h("section", { className: "card assistance-release-schedule" },
    h("div", { className: "section-title assistance-release-heading" },
      h("div", null,
        h("h2", null, isResident ? "Your scheduled assistance releases" : "Assistance release schedule"),
        h("p", { className: "section-note" }, isResident
          ? "Resident-specific assistance assigned to your account, separate from community program events."
          : isSecretary
            ? "Individual resident assignments are shared with Distribution Staff and the resident."
            : "Review resident assignments by date. Program events remain listed separately below.")
      ),
      h("span", { className: "badge" }, `${records.length} ${records.length === 1 ? "assignment" : "assignments"}`),
      isSecretary && h("button", {
        className: "primary assistance-release-add",
        type: "button",
        onClick: () => actions.setModal("assistance"),
      }, h(Icon, { id: "aid" }), " Record Assistance")
    ),
    groups.length
      ? h("div", { className: "assistance-release-groups" }, groups.map(([dateKey, dateRecords]) => {
        const isOverdue = dateKey !== "unscheduled" && dateKey < todayKey;
        const dateLabel = dateKey === "unscheduled"
          ? "Date not recorded"
          : displayDate(`${dateKey}T00:00:00`);

        return h("section", { className: `assistance-release-day${isOverdue ? " is-overdue" : ""}`, key: dateKey },
          h("div", { className: "assistance-release-day-heading" },
            h("div", null,
              h("h3", null, dateLabel),
              h("span", { className: "muted" }, `${dateRecords.length} ${dateRecords.length === 1 ? "resident" : "residents"}`)
            ),
            isOverdue && badge("Past due")
          ),
          h("div", { className: "assistance-release-day-list" }, dateRecords.map((record) => {
            const amount = String(record.amount || "").trim();
            const amountLabel = amount && amount.toLowerCase() !== "as released"
              ? amount
              : "To be confirmed at handover";
            const statusLabel = isOverdue
              ? "Past due"
              : isResident
                ? "Scheduled"
                : record.status === "Completed"
                  ? "Ready for release"
                  : "Scheduled";

            return h("article", { className: "assistance-release-item", key: record.id },
              h("div", { className: "assistance-release-item-main" },
                h("div", null,
                  h("b", null, isResident ? record.type : record.beneficiary),
                  h("span", { className: "muted" }, isResident ? "Assigned assistance" : `${record.type} · Record ${record.id}`)
                ),
                badge(statusLabel),
                h("div", { className: "assistance-release-amount" },
                  h("span", null, "Planned amount / items"),
                  h("b", null, amountLabel)
                ),
                isOverdue && h("small", { className: "assistance-release-overdue-note" }, isResident
                  ? "This release date has passed. Contact the barangay office for an updated schedule."
                  : "The assigned date has passed; confirm the new release date with the resident.")
              ),
              isSecretary
                ? h("button", {
                  className: "small-action",
                  type: "button",
                  onClick: () => actions.setModal({ type: "assistanceScheduleEdit", record }),
                  "aria-label": `Edit ${record.type} schedule for ${record.beneficiary}`,
                }, "Edit schedule")
                : mode === "distribution"
                  ? h("button", {
                    className: "small-action",
                    type: "button",
                    onClick: () => actions.navigate("Social Assistance"),
                  }, "Open handover desk")
                  : null
            );
          }))
        );
      }))
      : h("div", { className: "empty compact assistance-release-empty" }, isResident
        ? "Your assigned assistance and release dates will appear here once they are recorded by the Secretary."
        : isSecretary
          ? "No resident assistance has been scheduled yet. Record an assignment to publish its release date."
          : "No resident assistance is currently awaiting release.")
  );
}

function displayDate(value) {
  if (!value) return "Date not recorded";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}

function residentApplicationStatus(status) {
  return status === "Distributed" ? "Claimed" : status;
}

function ResidentRecentActivity({ actions }) {
  const applications = actions.data.applications || [];
  const applicationDistributions = (actions.data.distributions || []).filter((item) => item.applicationId !== null);
  const activityRows = [
    ...applicationDistributions.map((distribution) => {
      const application = applications.find((item) => Number(item.id) === Number(distribution.applicationId));
      return {
        date: distribution.distributedAt,
        row: [
          `REC-${distribution.id}`,
          application?.programName || distribution.benefit,
          displayDate(distribution.distributedAt),
          distribution.amount,
          badge("Received"),
          h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("My Reports / History") }, "View details"),
        ],
      };
    }),
    ...scopedAssistance(actions)
      .filter((item) => item.status === "Claimed")
      .map((item) => ({
        date: item.claimedAt || item.date,
        row: [
          item.id,
          item.type,
          displayDate(item.claimedAt || item.date),
          item.amount,
          badge("Received"),
          h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("My Reports / History") }, "View details"),
        ],
      })),
  ];
  const rows = activityRows
    .sort((first, second) => new Date(second.date || 0).getTime() - new Date(first.date || 0).getTime())
    .slice(0, 5);

  return h("section", { className: "card resident-recent-activity" },
    h("div", { className: "section-title" }, h("h2", null, "Recent Assistance Activity"), h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("My Reports / History") }, "View history")),
    h(Table, { headers: ["Record", "Assistance", "Date", "Amount / Items", "Status", "Details"], rows: rows.map((activity) => activity.row), empty: "Barangay-recorded assistance will appear here after distribution." })
  );
}

function ResidentHistory({ actions }) {
  const applications = actions.data.applications || [];
  const distributions = actions.data.distributions || [];
  const applicationDistributions = distributions.filter((item) => item.applicationId !== null);
  const assistanceRecords = scopedAssistance(actions);

  return h("section", { className: "grid resident-history" },
    h("div", { className: "notice" }, "This page contains previous assistance records and distributions linked to your resident account. New assistance is assigned by barangay staff based on your registered benefits."),
    h("section", { className: "card" },
      h("div", { className: "section-title" }, h("h2", null, "Previous Assistance Records"), h("span", { className: "badge" }, `${applications.length} records`)),
      applications.length
        ? h("div", { className: "list" }, applications.map((application) => {
          const distribution = applicationDistributions.find((item) => Number(item.applicationId) === Number(application.id));
          return h("article", { className: "list-item", key: application.id },
            h("b", null, `Record #${application.id} · ${application.programName}`),
            h("span", null, `${application.benefit} · Recorded ${displayDate(application.submittedAt)}`),
            badge(residentApplicationStatus(application.status)),
            distribution && h("span", null, `Received ${distribution.amount} on ${displayDate(distribution.distributedAt)}`),
            application.reviewNote && h("span", null, `Staff note: ${application.reviewNote}`)
          );
        }))
        : h("div", { className: "empty compact" }, "No previous assistance records are available.")
    ),
    h("section", { className: "card" },
      h("div", { className: "section-title" }, h("h2", null, "Assistance and Claim History"), h("span", { className: "badge" }, `${applicationDistributions.length + assistanceRecords.filter((item) => item.status === "Claimed").length} received`)),
      h(Table, {
        headers: ["Record", "Assistance", "Date received", "Amount / Items", "Status"],
        rows: [
          ...applicationDistributions.map((distribution) => {
            const application = applications.find((item) => Number(item.id) === Number(distribution.applicationId));
            return [`REC-${distribution.id}`, application?.programName || distribution.benefit, displayDate(distribution.distributedAt), distribution.amount, badge("Received")];
          }),
          ...assistanceRecords
            .filter((item) => item.status === "Claimed")
            .map((item) => [item.id, item.type, displayDate(item.claimedAt || item.date), item.amount, badge("Received")]),
        ],
        empty: "Staff-recorded claims and completed assistance will appear here after distribution.",
      })
    )
  );
}

function UserDashboard({ actions }) {
  const account = currentAccount(actions);
  const applications = actions.data.applications || [];
  const registeredBenefits = categoryList(actions.data.beneficiaries[0]?.category);
  const programs = residentUpcomingPrograms(actions);
  const distributions = actions.data.distributions || [];
  const assistanceRecords = scopedAssistance(actions);
  const applicationDistributions = distributions.filter((item) => item.applicationId !== null);
  const receivedAssistance = applicationDistributions.length + assistanceRecords.filter((item) => item.status === "Claimed").length;
  const nextProgram = programs[0];
  const nextProgramAllocation = nextProgram ? residentProgramAllocation(nextProgram, actions) : null;
  const latestApplication = applications.slice().sort((a, b) => String(b.reviewedAt || b.submittedAt).localeCompare(String(a.reviewedAt || a.submittedAt)))[0];
  const latestMessage = (actions.data.messages || []).slice().sort((a, b) => String(b.time).localeCompare(String(a.time)))[0];
  const firstName = (account.name || actions.session.name || "Resident").trim().split(/\s+/)[0];

  return h("div", { className: "grid resident-dashboard" },
    h("section", { className: "resident-welcome card" },
      h("div", { className: "resident-welcome-copy" },
        h("span", { className: "resident-welcome-eyebrow" }, "YOUR BARANGAYCARE PORTAL"),
        h("h2", null, `Welcome back, ${firstName}`),
        h("p", null, "Track assistance, check upcoming schedules, and stay up to date with your barangay."),
        h("div", { className: "resident-welcome-actions" },
          h("button", { className: "primary", type: "button", onClick: () => actions.navigate("My Assistance") }, "View my assistance"),
          h("button", { className: "secondary", type: "button", onClick: () => actions.navigate("Upcoming Schedules") }, "Browse schedules")
        )
      ),
      h("div", { className: "resident-account-status" },
        h("span", { className: "resident-account-status-mark", "aria-hidden": "true" }, "✓"),
        h("span", null, h("b", null, "Private account"), h("small", null, "Your information is personal to you"))
      )
    ),
    h("section", { className: "stats resident-stats" },
      h(Stat, { title: "Registered Benefits", value: registeredBenefits.length, note: "Used to match eligible programs", className: "resident-stat-benefits" }),
      h(Stat, { title: "Upcoming Schedules", value: programs.length, note: "Programs relevant to your account", className: "resident-stat-schedules" }),
      h(Stat, { title: "Assistance Records", value: assistanceRecords.length, note: "Assigned by barangay staff", className: "resident-stat-assistance" }),
      h(Stat, { title: "Assistance Received", value: receivedAssistance, note: "Recorded as received", className: "resident-stat-received" })
    ),
    h("section", { className: "content-grid resident-dashboard-panels" },
      h("section", { className: "card resident-next-schedule" },
        h("div", { className: "section-title" },
          h("div", null, h("h2", null, "Next eligible schedule"), h("p", { className: "section-note" }, "Upcoming programs that match your resident profile.")),
          nextProgram ? badge(nextProgram.status) : null
        ),
        nextProgram
          ? h(React.Fragment, null,
            nextProgramAllocation?.amount && h("p", { className: "resident-instructions" },
              h("b", null, nextProgramAllocation.isResidentSpecific ? "Planned amount / items for you: " : "Planned amount / items: "),
              nextProgramAllocation.amount
            ),
            nextProgramAllocation?.amount && h("small", { className: "muted" }, "Final amount or items will be confirmed when distribution staff records your handover."),
            h("b", { className: "resident-next-name" }, nextProgram.name),
            h("p", { className: "section-note" }, scheduleDetails(nextProgram)),
            nextProgram.instructions && h("p", { className: "resident-instructions" }, h("b", null, "Instructions: "), nextProgram.instructions),
            h("button", { className: "secondary resident-panel-action", type: "button", onClick: () => actions.navigate("Upcoming Schedules") }, "View all schedules")
          )
          : h("div", { className: "resident-empty-state" },
            h("span", { className: "resident-empty-icon", "aria-hidden": "true" }, "✓"),
            h("b", null, "You are all caught up"),
            h("span", null, "There are no upcoming programs matching your profile right now."),
            h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("Upcoming Schedules") }, "See all schedules")
          )
      ),
      h("section", { className: "card resident-updates" },
        h("div", { className: "section-title" },
          h("div", null, h("h2", null, "Latest Updates"), h("p", { className: "section-note" }, "Assistance updates and messages from barangay staff."))
        ),
        latestApplication
          ? h("button", { className: "resident-update-row", type: "button", onClick: () => actions.navigate("My Assistance") },
            h("span", null, `Previous assistance record #${latestApplication.id} · ${latestApplication.programName}`),
            badge(residentApplicationStatus(latestApplication.status)),
            h("small", null, latestApplication.reviewNote || `Recorded ${displayDate(latestApplication.submittedAt)}`)
          )
          : null,
        latestMessage
          ? h("button", { className: "resident-update-row", type: "button", onClick: () => actions.navigate("Messages & Notifications") },
            h("span", null, latestMessage.from === "resident" ? "Your message to staff" : "Message from barangay staff"),
            h("small", null, `${displayDate(latestMessage.time)} · ${latestMessage.body}`)
          )
          : null,
        !latestApplication && !latestMessage && h("div", { className: "resident-empty-state resident-updates-empty" },
          h("span", { className: "resident-empty-icon", "aria-hidden": "true" }, "i"),
          h("b", null, "No new updates"),
          h("span", null, "Assistance updates and staff messages will appear here."),
          h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("Messages & Notifications") }, "Open messages")
        )
      )
    ),
    h(ResidentRecentActivity, { actions }),
    h("div", { className: "resident-dashboard-actions" },
      h("button", { className: "secondary", type: "button", onClick: () => actions.navigate("My Assistance") }, "View my assistance"),
      h("button", { className: "secondary", type: "button", onClick: () => actions.navigate("My Reports / History") }, "View my history")
    )
  );
}

function AdminDashboard({ actions }) {
  const { data, session } = actions;
  if (["secretary", "assistance_officer", "distribution_staff"].includes(session.role)) {
    const role = session.role;
    const firstName = (session.name || roles[role]?.name || "Staff").split(" ")[0];
    const activeBeneficiaries = data.beneficiaries.filter((item) => item.status === "Active").length;
    const verifiedBeneficiaries = data.beneficiaries.filter((item) => item.verificationStatus === "Verified").length;
    const forValidation = data.beneficiaries.filter((item) => (item.verificationStatus || "Pending") === "Pending").length;
    const pendingAssistance = data.assistance.filter((item) => item.status === "Pending").length;
    const completedAssistance = data.assistance.filter((item) => item.status === "Claimed").length;
    const applications = data.applications || [];
    const openApplications = applications.filter((item) => ["Submitted", "Under Review"].includes(item.status)).length;
    const approvedApplications = applications.filter((item) => item.status === "Approved").length;
    const upcomingPrograms = data.programs.filter((item) => {
      const status = String(item.status || "").toLowerCase();
      return String(item.date || "").slice(0, 10) >= localDateKey() && !["completed", "cancelled"].includes(status);
    }).length;
    const staffMetrics = {
      secretary: [
        { title: "Resident profiles", value: data.beneficiaries.length, note: `${activeBeneficiaries} active on file` },
        { title: "Pending verification", value: forValidation, note: "Profiles requiring review" },
        { title: "Upcoming schedules", value: upcomingPrograms, note: "Community events ahead" },
        { title: "Verified residents", value: verifiedBeneficiaries, note: "Profiles reviewed by staff" },
      ],
      assistance_officer: [
        { title: "Open applications", value: openApplications, note: "Submitted or under review" },
        { title: "Pending assistance", value: pendingAssistance, note: "Awaiting review or release" },
        { title: "Approved applications", value: approvedApplications, note: "Ready for distribution" },
        { title: "Verified residents", value: verifiedBeneficiaries, note: "Profiles reviewed by staff" },
        { title: "Upcoming schedules", value: upcomingPrograms, note: "Saved program dates ahead" },
      ],
      distribution_staff: [
        { title: "Pending releases", value: pendingAssistance, note: "Awaiting handover" },
        { title: "Approved applications", value: approvedApplications, note: "Ready to distribute" },
        { title: "Completed assistance", value: completedAssistance, note: "Confirmed by distribution staff" },
        { title: "Upcoming schedules", value: upcomingPrograms, note: "Distribution events ahead" },
      ],
    };
    const dashboardLinks = {
      secretary: [
        ["users", "Add Resident Account", "Create a secure sign-in for a resident who needs account assistance.", "create-resident-account"],
        ["users", "Beneficiary records", "Review resident profiles and verification status.", "Beneficiary Management"],
        ["aid", "Assign assistance", "Select the residents and amounts eligible for assistance.", "Social Assistance"],
        ["calendar", "Office schedule", "Check upcoming programs and assigned staff.", "Scheduling and Distribution"],
        ["report", "Reports", "Review service and program summaries.", "Reports and Analytics"],
      ],
      assistance_officer: [
        ["aid", "Application review", `${openApplications} applications need review or follow-up.`, "Social Assistance"],
        ["users", "Beneficiary records", "Review verified resident profiles.", "Beneficiary Management"],
        ["report", "Reports", "Review assistance and distribution summaries.", "Reports and Analytics"],
      ],
      distribution_staff: [
        ["calendar", "Distribution schedule", `${upcomingPrograms} upcoming community events.`, "Scheduling and Distribution"],
        ["aid", "Release tracking", `${pendingAssistance} assistance records are pending.`, "Social Assistance"],
        ["users", "Resident accounts", "Review and update resident account details.", "Resident Accounts"],
        ["settings", "My profile", "Update your staff contact details and security.", "Profile"],
      ],
    };
    const metrics = staffMetrics[role] || [];
    const links = dashboardLinks[role] || [];

    return h("div", { className: `grid staff-workspace-dashboard staff-workspace-${role}` },
      h("section", { className: "staff-workspace-hero" },
        h("div", null,
          h("span", { className: "staff-workspace-eyebrow" }, `BARANGAYCARE · ${roles[role]?.name || "STAFF"} WORKSPACE`),
          h("h2", null, `Good day, ${firstName}`),
          h("p", null, `${roles[role]?.name || "Staff"} workspace for ${placeName(data.settings)}. Review your role-specific activity and get to frequent work quickly.`)
        ),
        h("div", { className: "staff-workspace-date" },
          h("span", null, h(Icon, { id: "calendar" }), "TODAY"),
          h("b", null, new Date().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric" })),
          h("small", null, data.settings?.officeHours || "Monday to Friday, 8:00 AM to 5:00 PM")
        )
      ),
      h("section", { className: "stats staff-workspace-stats", "aria-label": `${roles[role]?.name || "Staff"} service indicators` },
        metrics.map((metric) => h(Stat, { ...metric, className: "staff-workspace-stat", key: metric.title }))
      ),
      h("section", { className: "staff-workspace-links", "aria-label": "Workspace shortcuts" },
        h("div", { className: "staff-workspace-section-heading" },
          h("div", null, h("h2", null, "Your workspace"), h("p", null, "Shortcuts to the tools available for your role."))
        ),
        h("div", { className: "staff-workspace-link-grid" },
          links.map(([iconId, title, description, page]) => h("button", {
            className: "staff-workspace-link",
            type: "button",
            key: page,
            onClick: () => page === "create-resident-account" ? actions.setModal("user") : actions.navigate(page),
          },
            h("span", { className: "staff-workspace-link-icon" }, h(Icon, { id: iconId })),
            h("span", null, h("b", null, title), h("small", null, description)),
            h("span", { className: "staff-workspace-link-arrow", "aria-hidden": "true" }, "›")
          ))
        )
      ),
      h(SystemReadiness, { actions }),
      h("section", { className: "content-grid" },
        role === "assistance_officer" && h(WorkQueue, { actions, pending: data.assistance.filter((item) => item.status === "Pending") }),
        h(Chart, { data, title: "Beneficiaries by benefit type" })
      )
    );
  }

  if (session.role !== "admin") {
    const activeBeneficiaries = data.beneficiaries.filter((item) => item.status === "Active").length;
    const pendingAssistance = data.assistance.filter((item) => item.status === "Pending").length;
    const completedAssistance = data.assistance.filter((item) => item.status === "Claimed").length;
    const upcomingPrograms = data.programs.filter((item) => String(item.date || "").slice(0, 10) >= localDateKey() && item.status !== "Completed").length;

    return h("div", { className: "grid" },
      h("section", { className: "stats admin-stats" },
        h(Stat, { title: "Beneficiaries", value: data.beneficiaries.length, note: `${activeBeneficiaries} active profiles` }),
        h(Stat, { title: "Pending assistance", value: pendingAssistance, note: "Awaiting review or release" }),
        h(Stat, { title: "Assistance received", value: completedAssistance, note: "Confirmed by distribution staff" }),
        h(Stat, { title: "Upcoming schedules", value: upcomingPrograms, note: "Saved program dates ahead" })
      ),
      h(SystemReadiness, { actions }),
      h("section", { className: "content-grid" }, h(Chart, { data }))
    );
  }

  const applications = data.applications || [];
  const activeBeneficiaries = data.beneficiaries.filter((item) => item.status === "Active").length;
  const approvedApplications = applications.filter((item) => item.status === "Approved").length;
  const openApplications = applications.filter((item) => ["Submitted", "Under Review"].includes(item.status)).length;
  const upcomingPrograms = data.programs.filter((item) => {
    const status = String(item.status || "").toLowerCase();
    return String(item.date || "").slice(0, 10) >= localDateKey() && !["completed", "cancelled"].includes(status);
  }).length;
  const activeStaff = data.users.filter((item) => item.status === "Active" && !["User", "Resident"].includes(accountRoleLabel(item.role))).length;

  return h("div", { className: "grid" },
    h("section", { className: "admin-dashboard-hero" },
      h("div", null,
        h("span", { className: "admin-eyebrow" }, "BARANGAYCARE OPERATIONS"),
        h("h2", null, `Welcome back, ${session.name || "Administrator"}`),
        h("p", null, `A live overview of beneficiary services in ${placeName(data.settings)}.`)
      ),
      h("div", { className: "admin-dashboard-date" }, new Date().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric" }))
    ),
    h("section", { className: "stats admin-stats" },
      h(Stat, { title: "Total beneficiaries", value: data.beneficiaries.length, note: "Registered resident profiles", className: "admin-stat" }),
      h(Stat, { title: "Active beneficiaries", value: activeBeneficiaries, note: "Verified active profiles", className: "admin-stat" }),
      h(Stat, { title: "Pending applications", value: openApplications, note: "Submitted or under review", className: "admin-stat" }),
      h(Stat, { title: "Approved assistance", value: approvedApplications, note: "Approved, not yet distributed", className: "admin-stat" }),
      h(Stat, { title: "Upcoming distributions", value: upcomingPrograms, note: "Scheduled program events", className: "admin-stat" }),
      h(Stat, { title: "Active staff accounts", value: activeStaff, note: "Non-resident accounts", className: "admin-stat" })
    ),
    h(SystemReadiness, { actions }),
    h("section", { className: "content-grid" },
      h("div", { className: "admin-quick-actions", "aria-label": "Common administrative tasks" },
        h("button", { className: "admin-quick-action", type: "button", onClick: () => actions.navigate("Beneficiary Management") }, h("span", { className: "admin-quick-icon" }, "↗"), h("span", null, h("b", null, "View beneficiaries"), h("small", null, "Monitor registered resident profiles"))),
        h("button", { className: "admin-quick-action", type: "button", onClick: () => actions.navigate("Scheduling and Distribution") }, h("span", { className: "admin-quick-icon" }, "↗"), h("span", null, h("b", null, "View schedules"), h("small", null, "Monitor upcoming programs and distributions")))
      ),
      h(AdminAttention, { actions, items: adminNotificationItems(actions).slice(0, 5) })
    ),
    h("section", { className: "content-grid" },
      h(ProgramDistributionChart, { data }),
      h(Logs, { logs: data.logs.slice(0, 5), title: "Recent system activity" })
    )
  );
}

function ProgramDistributionChart({ data }) {
  const applications = new Map((data.applications || []).map((application) => [String(application.id), application]));
  const totals = new Map();
  (data.distributions || []).forEach((distribution) => {
    const application = distribution.applicationId === null ? null : applications.get(String(distribution.applicationId));
    const programName = application?.programName || "Unlinked assistance";
    totals.set(programName, (totals.get(programName) || 0) + 1);
  });
  const programs = [...totals.entries()].sort((first, second) => second[1] - first[1]).slice(0, 6);
  const max = Math.max(...programs.map((program) => program[1]), 1);

  return h("section", { className: "chart-panel admin-chart-panel" },
    h("div", { className: "section-title" }, h("div", null, h("h2", null, "Distributions by program"), h("p", { className: "section-note" }, "Recorded handovers grouped by linked program")), h("span", { className: "badge ok" }, `${data.distributions.length} records`)),
    programs.length
      ? h("div", { className: "admin-program-chart" }, programs.map(([name, count]) => h("div", { className: "admin-program-row", key: name },
          h("span", { title: name }, name),
          h("div", { className: "admin-program-track" }, h("div", { className: "admin-program-bar", style: { width: `${(count / max) * 100}%` } })),
          h("b", null, count)
        )))
      : h("div", { className: "empty compact" }, "Distribution totals will appear after a handover is recorded.")
  );
}

function adminNotificationItems(actions) {
  const { data, backendStatus } = actions;
  const items = [];
  const applications = (data.applications || [])
    .filter((application) => ["Submitted", "Under Review"].includes(application.status))
    .slice()
    .sort((first, second) => String(first.submittedAt || "").localeCompare(String(second.submittedAt || "")));
  applications.forEach((application) => items.push({
    id: `application-${application.id}`,
    title: application.status === "Submitted" ? "New assistance application" : "Application awaiting review",
    detail: `${application.beneficiary} · ${application.programName}`,
    date: application.submittedAt ? displayDate(application.submittedAt) : "Submission date unavailable",
    page: "Dashboard Overview",
    kind: "action",
  }));
  (data.beneficiaries || []).filter((beneficiary) => beneficiary.status === "Pending").forEach((beneficiary) => items.push({
    id: `beneficiary-${beneficiary.id}`,
    title: "Beneficiary verification needed",
    detail: `${beneficiary.name} · ${beneficiary.category || "Category not specified"}`,
    date: "Profile pending verification",
    page: "Beneficiary Management",
    kind: "action",
  }));
  (data.assistance || []).filter((record) => record.status === "Pending").forEach((record) => items.push({
    id: `assistance-${record.id}`,
    title: "Assistance awaiting release",
    detail: `${record.beneficiary} · ${record.type}`,
    date: record.date ? displayDate(record.date) : "Release date not specified",
    page: "Dashboard Overview",
    kind: "action",
  }));
  const todayKey = localDateKey();
  const lastDayKey = localDateKey(new Date(Date.now() + 7 * 24 * 60 * 60 * 1000));
  (data.programs || []).filter((program) => {
    const day = String(program.date || "").slice(0, 10);
    const status = String(program.status || "").toLowerCase();
    return day >= todayKey && day <= lastDayKey && !["completed", "cancelled"].includes(status);
  }).forEach((program) => items.push({
    id: `schedule-${program.id}`,
    title: "Distribution coming up",
    detail: `${program.name} · ${program.venue || "Venue to be confirmed"}`,
    date: displayDate(program.date),
    page: "Scheduling and Distribution",
    kind: "upcoming",
  }));
  (data.logs || []).filter((log) => /schedule|program|distribution/i.test(String(log.action || ""))).slice(0, 3).forEach((log, index) => items.push({
    id: `schedule-update-${index}-${log.time}`,
    title: "Recent schedule activity",
    detail: `${log.actor}: ${log.action}`,
    date: log.time,
    page: "Activity and Audit Logs",
    kind: "activity",
  }));
  if (!backendStatus.ok && backendStatus.source !== "checking") {
    items.unshift({
      id: "system-connection",
      title: "System connection needs attention",
      detail: backendStatus.message || "The Laravel service is currently unavailable.",
      date: "System alert",
      page: "System Settings",
      kind: "alert",
    });
  }
  return items;
}

function AdminAttention({ actions, items }) {
  return h("section", { className: "card admin-attention" },
    h("div", { className: "section-title" },
      h("div", null, h("h2", null, "Needs attention"), h("p", { className: "section-note" }, "Open items that may need staff follow-up")),
      h("button", { className: "small-action", type: "button", onClick: () => actions.navigate("Notifications") }, "View all")
    ),
    items.length
      ? h("div", { className: "admin-notification-list" }, items.map((item) => h(AdminNotificationItem, { item, actions, key: item.id })))
      : h("div", { className: "empty compact" }, "No items currently need attention.")
  );
}

function AdminNotificationItem({ item, actions }) {
  return h("button", { className: "admin-notification", type: "button", onClick: () => actions.navigate(item.page) },
    h("span", { className: `admin-notification-mark ${item.kind}`, "aria-hidden": "true" }, item.kind === "alert" ? "!" : item.kind === "upcoming" ? "↗" : "•"),
    h("span", { className: "admin-notification-copy" },
      h("b", null, item.title),
      h("span", null, item.detail),
      h("small", null, item.date)
    ),
    h("span", { className: "admin-notification-link" }, "Open")
  );
}

function AdminNotifications({ actions }) {
  const items = adminNotificationItems(actions);

  return h("section", { className: "grid admin-notifications-page" },
    h("div", { className: "admin-page-heading" },
      h("div", null, h("h2", null, "Notifications"), h("p", null, "Actionable updates generated from current system records.")),
      h("span", { className: "badge" }, `${items.length} current items`)
    ),
    h("div", { className: "notice" }, "Items update automatically as applications, beneficiary records, schedules, and system status change."),
    items.length
      ? h("div", { className: "admin-notification-list card" }, items.map((item) => h(AdminNotificationItem, { item, actions, key: item.id })))
      : h("section", { className: "card empty compact" }, "You are all caught up. There are no current items requiring attention.")
  );
}

function localDateKey(date = new Date()) {
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${day}`;
}

function BarangayDashboard({ actions }) {
  return h(CaptainMonitoring, { actions, dashboard: true });
}

function CaptainMonitoring({ actions, dashboard = false }) {
  const counts = actions.data.monitoring || {};
  const services = counts.services || {};
  const accountCount = counts.accounts ?? actions.data.users.length;
  const activeAccountCount = counts.activeAccounts ?? 0;
  const inactiveAccountCount = Math.max(0, accountCount - activeAccountCount);
  const activeAccountRate = accountCount ? Math.round((activeAccountCount / accountCount) * 100) : 0;
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [refreshedAt, setRefreshedAt] = useState("");
  const todayLabel = startOfToday().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric" });
  const staffRoles = ["Secretary", "Distribution Staff"].map((role) => {
    const accounts = actions.data.users.filter((user) => accountRoleLabel(user.role) === role);

    return {
      role,
      total: accounts.length,
      active: accounts.filter((user) => String(user.status || "").toLowerCase() === "active").length,
    };
  });
  const dashboardFollowUps = [
    {
      label: "Accounts needing review",
      value: inactiveAccountCount,
      detail: inactiveAccountCount
        ? "Inactive account access may need follow-up."
        : "No accounts are currently marked inactive.",
      action: inactiveAccountCount ? "Review accounts" : "Open account directory",
      page: "Staff and Resident Accounts",
      icon: "users",
      tone: inactiveAccountCount ? "attention" : "clear",
    },
    {
      label: "Awaiting handover",
      value: services.awaitingHandover ?? 0,
      detail: "Read-only count; Distribution Staff record completed handovers.",
      action: "View monitoring",
      page: "Monitoring",
      icon: "aid",
      tone: "neutral",
    },
    {
      label: "Upcoming schedules",
      value: services.upcomingPrograms ?? 0,
      detail: "Upcoming program count shared from the staff workspace.",
      action: "View monitoring",
      page: "Monitoring",
      icon: "calendar",
      tone: "neutral",
    },
  ];
  const monitoringStats = [
    { title: "Resident profiles", value: services.residentProfiles ?? 0, note: `${services.activeResidentProfiles ?? 0} active profiles`, className: "monitoring-stat resident" },
    { title: "Assistance assignments", value: services.assistanceAssignments ?? 0, note: "Secretary-managed records", className: "monitoring-stat assistance" },
    { title: "Awaiting handover", value: services.awaitingHandover ?? 0, note: "Pending distribution activity", className: "monitoring-stat pending" },
    { title: "Recorded handovers", value: services.completedHandovers ?? 0, note: "Completed distribution records", className: "monitoring-stat completed" },
  ];

  async function refreshMonitoring() {
    setIsRefreshing(true);

    try {
      await actions.refreshData();
      setRefreshedAt(new Date().toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" }));
    } catch (error) {
      actions.notify(`Could not refresh monitoring data: ${error.message}`);
    } finally {
      setIsRefreshing(false);
    }
  }

  return h("section", { className: `grid captain-dashboard ${dashboard ? "captain-dashboard-home" : "captain-monitoring-page"}` },
    dashboard
      ? h("section", { className: "captain-executive-hero" },
        h("div", { className: "captain-executive-copy" },
          h("span", { className: "captain-executive-eyebrow" }, h("span", { "aria-hidden": "true" }), "BARANGAYCARE · CAPTAIN'S OFFICE"),
          h("h2", null, `Good day, ${(actions.session.name || "Captain").split(" ")[0]}.`),
          h("p", null, "Your community operations at a glance. Review account access, staff coverage, assistance activity, and upcoming work—all in one place."),
          h("div", { className: "captain-executive-date" }, h(Icon, { id: "calendar" }), todayLabel)
        ),
        h("aside", { className: "captain-access-snapshot", "aria-label": "Account access summary" },
          h("div", { className: "captain-access-snapshot-heading" },
            h("span", null, "ACCOUNT ACCESS"),
            h("span", { className: "captain-access-live" }, h("span", { "aria-hidden": "true" }), "LIVE")
          ),
          h("div", { className: "captain-access-snapshot-main" },
            h("div", { className: "captain-access-ring", style: { "--access-rate": `${activeAccountRate}%` }, role: "img", "aria-label": `${activeAccountRate}% of monitored accounts are active` },
              h("span", null, `${activeAccountRate}%`)
            ),
            h("div", { className: "captain-access-copy" },
              h("strong", null, `${activeAccountCount} of ${accountCount} active`),
              h("span", null, "Monitored accounts")
            )
          ),
          h("div", { className: `captain-access-follow-up ${inactiveAccountCount ? "needs-review" : ""}` },
            h("span", null, h("b", null, inactiveAccountCount), inactiveAccountCount === 1 ? " account needs review" : " accounts need review"),
            h("button", { className: "captain-access-link", type: "button", onClick: () => actions.navigate("Staff and Resident Accounts") }, "View directory", h("span", { "aria-hidden": "true" }, "→"))
          )
        ),
        h("div", { className: "captain-executive-actions" },
          h("button", { className: "secondary", type: "button", onClick: refreshMonitoring, disabled: isRefreshing }, isRefreshing ? "Refreshing overview..." : "Refresh overview"),
          h("span", { className: "captain-refresh-status", "aria-live": "polite" }, refreshedAt ? `Updated at ${refreshedAt}` : "Overview updates when records change")
        )
      )
      : h("section", { className: "captain-monitoring-hero" },
        h("div", { className: "captain-monitoring-copy" },
          h("span", { className: "staff-dashboard-eyebrow" }, "BARANGAYCARE · OPERATIONS"),
          h("h2", null, "Service monitoring"),
          h("p", null, "Review aggregate activity across resident services, assistance assignments, and distribution handovers. Individual resident transactions and contact details are not shown."),
          h("div", { className: "captain-monitoring-meta" },
            h("span", null, h(Icon, { id: "calendar" }), todayLabel),
            h("span", null, h(Icon, { id: "lock" }), "Read-only monitoring")
          )
        ),
        h("div", { className: "captain-monitoring-controls" },
          h("div", { className: "captain-monitoring-status" },
            h("span", { className: "captain-monitoring-status-mark", "aria-hidden": "true" }),
            h("span", null, h("strong", null, "Shared service data"), h("small", null, "Aggregate totals only")
            )
          ),
          h("button", { className: "secondary", type: "button", onClick: refreshMonitoring, disabled: isRefreshing }, isRefreshing ? "Refreshing..." : "Refresh monitoring"),
          h("button", { className: "primary", type: "button", onClick: () => actions.navigate("Staff and Resident Accounts") }, "Manage staff accounts"),
          h("span", { className: "captain-refresh-status", "aria-live": "polite" }, refreshedAt ? `Updated at ${refreshedAt}` : "Auto-updates when records change")
        )
      ),
    h("section", { className: `stats staff-stats ${dashboard ? "captain-account-stats" : "captain-monitoring-stats"}` },
      dashboard
        ? [
          h(Stat, { title: "Accounts monitored", value: accountCount, note: "Staff and resident accounts", className: "staff-stat", key: "accounts" }),
          h(Stat, { title: "Active accounts", value: activeAccountCount, note: "Currently marked active", className: "staff-stat", key: "active" }),
          h(Stat, { title: "Resident accounts", value: counts.residentAccounts ?? 0, note: "Account status monitoring", className: "staff-stat", key: "residents" }),
          h(Stat, { title: "Staff accounts", value: counts.staffAccounts ?? 0, note: "Office coverage monitoring", className: "staff-stat", key: "staff" }),
        ]
        : monitoringStats.map((item) => h(Stat, { ...item, key: item.title }))
    ),
    dashboard && h("section", { className: "captain-operational-pulse", "aria-label": "Operational overview" },
      h("div", { className: "captain-pulse-heading" },
        h("div", null,
          h("span", { className: "staff-dashboard-eyebrow" }, "QUICK OVERVIEW"),
          h("h2", null, "Operational pulse"),
          h("p", null, "A privacy-safe summary of account access and service activity.")
        ),
        h("span", { className: "captain-pulse-tag" }, h(Icon, { id: "lock" }), " Aggregate data")
      ),
      h("div", { className: "captain-pulse-grid" }, dashboardFollowUps.map((item) => h("article", {
        className: `captain-pulse-card ${item.tone}`,
        key: item.label,
      },
      h("div", { className: "captain-pulse-card-heading" },
        h("span", { className: "captain-pulse-icon", "aria-hidden": "true" }, h(Icon, { id: item.icon })),
        h("span", { className: "captain-pulse-value" }, item.value)
      ),
      h("h3", null, item.label),
      h("p", null, item.detail),
      h("button", { className: "captain-pulse-link", type: "button", onClick: () => actions.navigate(item.page) },
        item.action,
        h("span", { "aria-hidden": "true" }, "→")
      )
      )))
    ),
    !dashboard && h("div", { className: "captain-monitoring-section-label" },
      h("span", null, "TEAM COVERAGE"),
      h("p", null, "Check active and inactive access by staff role.")
    ),
    !dashboard && h("section", { className: "captain-service-overview card", "aria-label": "Connected service overview" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h2", null, "Service activity by team"),
          h("p", { className: "section-note" }, "Aggregate totals shared from resident, Secretary, and Distribution Staff workspaces.")
        ),
        h("span", { className: "badge ok" }, "Shared records")
      ),
      h("div", { className: "captain-service-cards" },
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "RESIDENT SERVICES"),
          h("b", null, services.residentProfiles ?? 0),
          h("span", null, "Resident profiles"),
          h("small", null, `${services.activeResidentProfiles ?? 0} active profiles`)
        ),
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "SECRETARY WORKSPACE"),
          h("b", null, services.assistanceAssignments ?? 0),
          h("span", null, "Assistance assignments"),
          h("small", null, `${services.upcomingPrograms ?? 0} upcoming schedules`)
        ),
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "DISTRIBUTION WORKSPACE"),
          h("b", null, services.completedHandovers ?? 0),
          h("span", null, "Recorded handovers"),
          h("small", null, `${services.awaitingHandover ?? 0} assignments awaiting handover`)
        )
      )
    ),
    h("section", { className: `captain-staff-coverage card ${dashboard ? "" : "captain-monitoring-coverage"}`, "aria-label": "Staff account coverage" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h2", null, "Staff account coverage"),
          h("p", { className: "section-note" }, "Active access by office role.")
        ),
        h("button", { className: "ghost", type: "button", onClick: () => actions.navigate("Staff and Resident Accounts") }, "View accounts")
      ),
      h("div", { className: "captain-role-cards" }, staffRoles.map(({ role, total, active }) => h("article", { className: "captain-role-card", key: role },
        h("span", null, role),
        h("div", { className: "captain-role-card-count" }, h("b", null, total), h("small", null, `${total} ${total === 1 ? "account" : "accounts"}`)),
        h("div", { className: "captain-role-meter", role: "meter", "aria-label": `${role} active account coverage`, "aria-valuemin": 0, "aria-valuemax": 100, "aria-valuenow": total ? Math.round((active / total) * 100) : 0 },
          h("span", { style: { width: `${total ? Math.round((active / total) * 100) : 0}%` } })
        ),
        h("small", null, `${active} active · ${total - active} inactive`)
      )))
    ),
    dashboard && h("section", { className: "captain-service-overview card", "aria-label": "Connected service overview" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h2", null, "Connected service overview"),
          h("p", { className: "section-note" }, "Live, read-only totals from resident services, Secretary assignments, and distribution handovers.")
        ),
        h("span", { className: "badge ok" }, "Shared records")
      ),
      h("div", { className: "captain-service-cards" },
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "RESIDENT SERVICES"),
          h("b", null, services.residentProfiles ?? 0),
          h("span", null, "Resident profiles"),
          h("small", null, `${services.activeResidentProfiles ?? 0} active profiles`)
        ),
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "SECRETARY WORKSPACE"),
          h("b", null, services.assistanceAssignments ?? 0),
          h("span", null, "Assistance assignments"),
          h("small", null, `${services.upcomingPrograms ?? 0} upcoming schedules`)
        ),
        h("article", { className: "captain-service-card" },
          h("span", { className: "program-monitor-kicker" }, "DISTRIBUTION WORKSPACE"),
          h("b", null, services.completedHandovers ?? 0),
          h("span", null, "Recorded handovers"),
          h("small", null, `${services.awaitingHandover ?? 0} assignments awaiting handover`)
        )
      )
    ),
    dashboard && h("section", { className: "captain-dashboard-activity card", "aria-label": "Recent activity and upcoming schedules" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h2", null, "Recent operations and upcoming schedules"),
          h("p", { className: "section-note" }, "A privacy-safe view of recent releases and the next scheduled programs.")
        ),
        h("span", { className: "badge ok" }, "Read-only")
      ),
      h("div", { className: "captain-dashboard-activity-grid" },
        h("article", { className: "captain-dashboard-activity-summary" },
          h("span", { className: "program-monitor-kicker" }, "LAST 30 DAYS"),
          h("b", null, services.recentHandovers ?? 0),
          h("span", null, "Recorded assistance handovers"),
          h("small", null, "Resident names and claimed amounts are not included.")
        ),
        h("div", { className: "captain-dashboard-upcoming" },
          h("h3", null, "Next scheduled programs"),
          counts.upcomingSchedules?.length
            ? h("ul", null, counts.upcomingSchedules.map((program) => h("li", { key: `${program.name}-${program.date}` },
              h("span", null,
                h("strong", null, program.name),
                h("small", null, [displayDate(program.date), program.time, program.venue].filter(Boolean).join(" · "))
              )
            )))
            : h("p", { className: "empty compact" }, "There are no upcoming programs scheduled.")
        )
      )
    ),
    h("div", { className: "notice" }, "Account and service summaries are read-only. Secretaries manage assignments; Distribution Staff record completed handovers.")
  );
}

//#endregion
//#region Staff dashboards and action queues
function staffAttentionItems(queues) {
  const { openApplications, forValidation, pendingAid, upcoming, todayKey } = queues;
  const items = [];
  openApplications.slice(0, 3).forEach((application) => items.push({
    id: `staff-application-${application.id}`,
    title: application.status === "Submitted" ? "New assistance application" : "Application under review",
    detail: `${application.beneficiary} · ${application.programName}`,
    date: application.submittedAt ? `Submitted ${displayDate(application.submittedAt)}` : "Submission date unavailable",
    page: "Assistance",
    kind: "action",
  }));
  forValidation.slice(0, 2).forEach((beneficiary) => items.push({
    id: `staff-beneficiary-${beneficiary.id}`,
    title: "Beneficiary verification needed",
    detail: `${beneficiary.name} · ${beneficiary.category || "Benefit category not specified"}`,
    date: "Profile pending verification",
    page: "Beneficiaries",
    kind: "action",
  }));
  pendingAid.filter((record) => {
    const date = String(record.date || "").slice(0, 10);
    return date !== "" && date < todayKey;
  }).slice(0, 2).forEach((record) => items.push({
    id: `staff-overdue-${record.id}`,
    title: "Assistance release is overdue",
    detail: `${record.beneficiary} · ${record.type}`,
    date: `Due ${displayDate(record.date)}`,
    page: "Assistance",
    kind: "alert",
  }));
  upcoming.slice(0, 3).forEach((program) => items.push({
    id: `staff-schedule-${program.id}`,
    title: "Distribution coming up",
    detail: `${program.name} · ${program.venue || "Venue to be confirmed"}`,
    date: displayDate(program.date),
    page: "Programs and Schedule",
    kind: "upcoming",
  }));
  return items.slice(0, 8);
}

function StaffActionQueue({ actions, items }) {
  return h("section", { className: "card staff-action-queue" },
    h("div", { className: "section-title" },
      h("div", null, h("h2", null, "Office action queue"), h("p", { className: "section-note" }, "Prioritize pending reviews, resident records, and upcoming distributions")),
      h("span", { className: `badge ${items.length ? "warn" : "ok"}` }, `${items.length} items`)
    ),
    items.length
      ? h("div", { className: "admin-notification-list" }, items.map((item) => h(AdminNotificationItem, { item, actions, key: item.id })))
      : h("div", { className: "empty compact" }, "No items currently need staff follow-up.")
  );
}

function SystemReadiness({ actions, compact = false }) {
  const checks = [
    ["Role-Based Access", "Enabled"],
    ["Assistance Privacy", "Enabled"],
    ["Claim Tracking", actions.data.assistance.some((item) => item.status === "Claimed") ? "Active" : "Ready"],
    ["Backend Mode", window.BarangayCareService.mode.includes("Laravel") ? "Laravel/MySQL" : window.BarangayCareService.mode.includes("WAMP") ? "WAMP" : "Local"],
  ];
  return h("section", { className: compact ? "readiness compact" : "readiness" }, checks.map(([label, value]) => h("div", { className: "readiness-item", key: label }, h("span", null, label), h("b", null, value))));
}

function QuickActions({ actions }) {
  const items = [
    ["users", "Add a resident", "Record a resident before releasing assistance.", () => actions.setModal("beneficiary")],
    ["aid", "Record assistance", "Track support awaiting release or already received.", () => actions.setModal("assistance")],
    ["calendar", "Post a schedule", "Set the benefit, date, time, venue, and assigned staff.", () => actions.setModal("program")],
  ];
  return h("section", { className: "quick-grid" }, items.map(([iconId, title, text, onClick]) => h("button", { className: "quick-card", key: title, onClick }, h(Icon, { id: iconId }), h("b", null, title), h("span", null, text))));
}

function dueLabel(date, todayKey) {
  const day = String(date || "").slice(0, 10);
  if (day < todayKey) return "Overdue";
  if (day === todayKey) return "Due today";
  return day;
}

function WorkQueue({ actions, pending = [], todayKey = localDateKey(), title = "For release" }) {
  const assistancePage = actions.session.role === "admin"
    ? "Dashboard Overview"
    : ["secretary", "assistance_officer", "distribution_staff", "auditor"].includes(actions.session.role) ? "Social Assistance" : "Assistance";
  const canRecord = ["barangay", "assistance_officer"].includes(actions.session.role);
  return h("section", { className: "card monitoring-work-queue" }, h("div", { className: "section-title" }, h("h2", null, title), h("span", { className: "badge warn" }, `${pending.length} waiting`)), pending.length ? h("div", { className: "list" }, pending.map((item) => h("button", { className: "list-item action-row", key: item.id, onClick: () => actions.navigate(assistancePage) }, h("b", null, item.beneficiary), h("span", null, `${item.type} · ${item.amount}`), h("span", null, dueLabel(item.date, todayKey))))) : h("div", { className: "empty compact" }, "Nothing is waiting for release."), canRecord && h("button", { className: "secondary", onClick: () => actions.setModal("assistance") }, h(Icon, { id: "aid" }), " Record a release"));
}

function ValidationQueue({ actions, people }) {
  return h("section", { className: "card" }, h("div", { className: "section-title" }, h("h2", null, "For validation"), h("span", { className: "badge warn" }, `${people.length} profiles`)), people.length ? h("div", { className: "list" }, people.map((person) => h("button", { className: "list-item action-row", key: person.id, onClick: () => actions.navigate("Beneficiaries") }, h("b", null, person.name), h("span", null, `${person.purok} · ${person.category}`), h("span", null, "Verification pending")))) : h("div", { className: "empty compact" }, "All resident profiles have been reviewed."), h("button", { className: "secondary", onClick: () => actions.navigate("Beneficiaries") }, "Open residents"));
}
//#endregion
//#region Beneficiary records and verification
function SearchBar({ query, setQuery, placeholder }) {
  return h("div", { className: "search" }, h(Icon, { id: "search" }), h("input", { className: "input", placeholder, value: query, onChange: (event) => setQuery(event.target.value) }));
}

function FilterSelect({ label, value, onChange, options }) {
  return h("label", { className: "filter-select" }, h("span", null, label), h("select", { className: "select", value, onChange: (event) => onChange(event.target.value) }, options.map((option) => h("option", { key: option }, option))));
}

function linkedAssistanceLabel(assistance, beneficiary) {
  const types = [];
  assistance.forEach((item) => {
    if (String(item.beneficiary || "").trim().toLowerCase() !== String(beneficiary.name || "").trim().toLowerCase()) return;
    const type = String(item.type || "").trim();
    if (!type || types.some((itemType) => itemType.toLowerCase() === type.toLowerCase())) return;
    types.push(type);
  });
  if (types.length) return types.join(", ");
  return beneficiary.category || "None";
}

function StaffRecords({ actions, activePage }) {
  const assistanceTab = activePage === "Assistance";
  const beneficiaryCount = (actions.data.beneficiaries || []).length;
  const assistance = actions.data.assistance || [];

  return h("section", { className: "grid staff-records" },
    h("div", { className: "notice" }, "Manage resident profiles and their assistance records together. Use the tabs to switch between beneficiary details and assistance releases."),
    h("div", { className: "staff-record-tabs", role: "tablist", "aria-label": "Beneficiary and assistance records" },
      h("button", {
        className: `staff-record-tab ${assistanceTab ? "" : "active"}`,
        type: "button",
        role: "tab",
        "aria-selected": !assistanceTab,
        onClick: () => actions.navigate("Beneficiaries"),
      }, h("span", null, "Beneficiaries"), h("span", { className: "staff-record-count" }, beneficiaryCount)),
      h("button", {
        className: `staff-record-tab ${assistanceTab ? "active" : ""}`,
        type: "button",
        role: "tab",
        "aria-selected": assistanceTab,
        onClick: () => actions.navigate("Assistance"),
      }, h("span", null, "Assistance records"), h("span", { className: "staff-record-count" }, assistance.length))
    ),
    h("div", { role: "tabpanel", "aria-label": assistanceTab ? "Assistance records" : "Beneficiaries" },
      assistanceTab
        ? h(Assistance, { actions, editable: true })
        : h(Beneficiaries, { actions, editable: true })
    )
  );
}

function syncAssistanceWithCategory(assistance, beneficiaryName, previousCategory, nextCategory, staffName) {
  const nextTypes = categoryList(nextCategory);
  const removed = categoryList(previousCategory).filter((type) => !nextTypes.some((item) => item.toLowerCase() === type.toLowerCase()));
  const kept = assistance.filter((item) => {
    if (String(item.beneficiary || "").trim().toLowerCase() !== beneficiaryName.toLowerCase()) return true;
    return !removed.some((type) => type.toLowerCase() === String(item.type || "").trim().toLowerCase());
  });
  nextTypes.forEach((type, index) => {
    const exists = kept.some((item) => String(item.beneficiary || "").trim().toLowerCase() === beneficiaryName.toLowerCase() && String(item.type || "").trim().toLowerCase() === type.toLowerCase());
    if (exists) return;
    kept.unshift({
      id: `A-${Date.now()}-${index}`,
      beneficiary: beneficiaryName,
      type,
      amount: "As released",
      date: today(),
      status: "Pending",
      staff: staffName,
    });
  });
  return kept;
}

function Beneficiaries({ actions, editable }) {
  const [category, setCategory] = useState("All");
  const [status, setStatus] = useState("All");
  const [reviewStatus, setReviewStatus] = useState("All");
  const [verificationUpdatingId, setVerificationUpdatingId] = useState("");
  const visibleBeneficiaries = editable ? actions.data.beneficiaries : scopedData(actions).beneficiaries;
  const assistanceOptions = useMemo(() => {
    const options = [];
    visibleBeneficiaries.forEach((beneficiary) => {
      categoryList(linkedAssistanceLabel(actions.data.assistance, beneficiary)).forEach((type) => {
        if (type !== "None" && !options.some((item) => item.toLowerCase() === type.toLowerCase())) options.push(type);
      });
    });
    return options;
  }, [visibleBeneficiaries, actions.data.assistance]);
  const normalizedQuery = actions.query.trim().toLowerCase();
  const rows = useMemo(() => visibleBeneficiaries.filter((item) => {
    const assistanceLabel = linkedAssistanceLabel(actions.data.assistance, item);
    const searchableText = [item.name, item.id, item.purok, item.contact, assistanceLabel].join(" ").toLowerCase();
    const matchesReviewStatus = reviewStatus === "All"
      || (reviewStatus === "Possible duplicate"
        ? item.possibleDuplicate === true
        : (item.verificationStatus || "Pending") === reviewStatus);
    return searchableText.includes(normalizedQuery)
      && (category === "All" || hasCategory(assistanceLabel, category))
      && (status === "All" || item.status === status)
      && matchesReviewStatus;
  }), [visibleBeneficiaries, actions.data.assistance, normalizedQuery, category, status, reviewStatus]);
  const hasFilters = Boolean(actions.query.trim()) || category !== "All" || status !== "All" || reviewStatus !== "All";
  const activeCount = visibleBeneficiaries.filter((beneficiary) => beneficiary.status === "Active").length;
  const canViewVerification = actions.session.role !== "user";
  const pendingCount = canViewVerification
    ? visibleBeneficiaries.filter((beneficiary) => (beneficiary.verificationStatus || "Pending") === "Pending").length
    : 0;
  const possibleDuplicateCount = canViewVerification
    ? visibleBeneficiaries.filter((beneficiary) => beneficiary.possibleDuplicate === true).length
    : 0;
  const categorizedCount = visibleBeneficiaries.filter((beneficiary) => categoryList(linkedAssistanceLabel(actions.data.assistance, beneficiary)).some((type) => type !== "None")).length;
  function clearDirectoryFilters() {
    actions.setQuery("");
    setCategory("All");
    setStatus("All");
    setReviewStatus("All");
  }
  async function changeVerificationStatus(beneficiary, nextStatus) {
    setVerificationUpdatingId(String(beneficiary.id));
    try {
      const updated = await updateBeneficiaryVerification(actions, beneficiary, nextStatus);
      if (updated && nextStatus === "Pending") setReviewStatus("Pending");
    } finally {
      setVerificationUpdatingId("");
    }
  }
  const beneficiaryList = h("section", { className: "card beneficiary-directory" },
    h("div", { className: "section-title" },
      h("div", null, h("h2", null, "Beneficiary list"), h("p", { className: "section-note" }, "Review resident profiles and verification. Reopened profiles return to the pending review queue. Possible matches stay on file and are flagged for review, not blocked.")),
      h("span", { className: "badge beneficiary-count-pill" }, `Showing ${rows.length} of ${visibleBeneficiaries.length} resident profiles${actions.session.role === "secretary" ? ` · ${pendingCount} pending review` : ""}`)
    ),
    h("div", { className: "toolbar beneficiary-directory-toolbar" },
      h(SearchBar, { query: actions.query, setQuery: actions.setQuery, placeholder: "Search residents by name, purok, or assistance" }),
      h(FilterSelect, { label: "Assistance", value: category, onChange: setCategory, options: ["All", ...assistanceOptions] }),
      h(FilterSelect, { label: "Profile status", value: status, onChange: setStatus, options: ["All", "Active", "Pending"] }),
      actions.session.role === "secretary" && h(FilterSelect, { label: "Review status", value: reviewStatus, onChange: setReviewStatus, options: ["All", "Pending", "Verified", "Possible duplicate"] }),
      hasFilters && h("button", {
        className: "secondary beneficiary-clear-filters",
        type: "button",
        onClick: clearDirectoryFilters,
      }, "Clear filters")
    ),
    rows.length
      ? h("div", { className: "beneficiary-name-list" }, rows.map((beneficiary) =>
        h("div", { className: "beneficiary-name-row", key: beneficiary.id },
          h("div", { className: "beneficiary-person" },
            h("span", { className: "beneficiary-avatar", "aria-hidden": "true" }, String(beneficiary.name || "?").trim().charAt(0).toUpperCase()),
            h("div", { className: "beneficiary-summary" },
              h("button", {
                className: "beneficiary-name-button",
                type: "button",
                onClick: () => actions.setModal({ type: "beneficiaryEdit", record: beneficiary }),
                "aria-label": `View or edit ${beneficiary.name}`,
              }, beneficiary.name),
              h("span", { className: "beneficiary-meta" }, `${beneficiary.purok || "Purok not specified"} · ${linkedAssistanceLabel(actions.data.assistance, beneficiary)}`)
            )
          ),
          h("div", { className: "beneficiary-row-status" },
            badge(beneficiary.status),
            badge(beneficiary.verificationStatus || "Pending"),
            beneficiary.possibleDuplicate && h("span", { className: "badge danger", title: "Review this record against existing resident profiles." }, "Possible duplicate"),
            actions.session.role === "secretary" && h("button", {
              className: `small-action${verificationUpdatingId === String(beneficiary.id) ? " is-saving" : ""}`,
              type: "button",
              disabled: verificationUpdatingId === String(beneficiary.id),
              onClick: () => changeVerificationStatus(beneficiary, beneficiary.verificationStatus === "Verified" ? "Pending" : "Verified"),
              "aria-label": `${beneficiary.verificationStatus === "Verified" ? "Reopen review for" : "Mark verified"} ${beneficiary.name}`,
            }, beneficiary.verificationStatus === "Verified" ? "Reopen review" : "Mark verified")
          ),
          h("div", { className: "row-actions beneficiary-row-actions" },
            h("button", {
              className: "small-action",
              type: "button",
              onClick: () => actions.setModal({ type: "beneficiaryEdit", record: beneficiary }),
              "aria-label": `Edit ${beneficiary.name}`,
            }, "Edit"),
            h("button", {
              className: "small-action danger",
              type: "button",
              onClick: () => deleteBeneficiary(actions, beneficiary),
              "aria-label": `Delete ${beneficiary.name}`,
            }, "Delete")
          )
        )
      ))
      : h("div", { className: "empty compact" }, visibleBeneficiaries.length
        ? "No resident profiles match the selected search and filters."
        : "No beneficiary records are available yet.")
  );
  const readOnlyDirectory = h("section", { className: "grid beneficiary-readonly-directory" },
    h("section", { className: "beneficiary-directory-hero" },
      h("div", null,
        h("span", { className: "beneficiary-directory-eyebrow" }, h(Icon, { id: "users" }), " RESIDENT RECORDS"),
        h("h2", null, "Beneficiary directory"),
        h("p", null, "Review resident profiles, assistance eligibility, and recent service activity.")
      ),
      h("div", { className: "beneficiary-directory-access" },
        h("span", { className: "beneficiary-access-mark", "aria-hidden": "true" }, h(Icon, { id: "lock" })),
        h("span", null, h("b", null, "View-only access"), h("small", null, "Profile changes are managed by authorized staff"))
      )
    ),
    h("section", { className: "beneficiary-overview-stats", "aria-label": "Beneficiary summary" },
      h(MiniCard, { value: visibleBeneficiaries.length, label: "Registered residents" }),
      h(MiniCard, { value: activeCount, label: "Active profiles" }),
      canViewVerification && h(MiniCard, { value: pendingCount, label: "Pending review" }),
      h(MiniCard, { value: categorizedCount, label: "With assistance categories" }),
      canViewVerification && h("button", {
        className: `mini-card beneficiary-duplicate-shortcut ${reviewStatus === "Possible duplicate" ? "selected" : ""}`,
        type: "button",
        "aria-pressed": reviewStatus === "Possible duplicate",
        onClick: () => setReviewStatus(reviewStatus === "Possible duplicate" ? "All" : "Possible duplicate"),
      }, h("b", null, possibleDuplicateCount), h("span", null, "Possible duplicates"), h("small", null, "Filter flagged profiles"))
    ),
    h("section", { className: "card beneficiary-directory beneficiary-readonly-list" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h2", null, "Resident profiles"),
          h("p", { className: "section-note" }, "Search and filter the registered residents for this barangay.")
        ),
        h("span", { className: "badge beneficiary-count-pill" }, `${rows.length} of ${visibleBeneficiaries.length} residents`)
      ),
      h("div", { className: "toolbar beneficiary-directory-toolbar" },
        h(SearchBar, { query: actions.query, setQuery: actions.setQuery, placeholder: "Search by name, ID, purok, or contact" }),
        h(FilterSelect, { label: "Assistance category", value: category, onChange: setCategory, options: ["All", ...assistanceOptions] }),
        h(FilterSelect, { label: "Profile status", value: status, onChange: setStatus, options: ["All", "Active", "Pending"] }),
        canViewVerification && h(FilterSelect, { label: "Review status", value: reviewStatus, onChange: setReviewStatus, options: ["All", "Pending", "Verified", "Possible duplicate"] }),
        hasFilters && h("button", {
          className: "secondary beneficiary-clear-filters",
          type: "button",
          onClick: clearDirectoryFilters,
        }, "Clear filters")
      ),
      rows.length
        ? h("div", { className: "beneficiary-profile-list" }, rows.map((beneficiary) => {
          const assistanceTypes = categoryList(linkedAssistanceLabel(actions.data.assistance, beneficiary));
          return h("article", { className: "beneficiary-profile-card", key: beneficiary.id },
            h("div", { className: "beneficiary-profile-top" },
              h("div", { className: "beneficiary-person" },
                h("span", { className: "beneficiary-avatar", "aria-hidden": "true" }, String(beneficiary.name || "?").trim().charAt(0).toUpperCase()),
                h("div", { className: "beneficiary-summary" },
                  h("h3", null, beneficiary.name || "Unnamed resident"),
                  h("span", { className: "beneficiary-id" }, `Resident ID · ${beneficiary.id || "Not assigned"}`)
                )
              ),
              h("div", { className: "beneficiary-row-status" },
                badge(beneficiary.status || "Unknown"),
                beneficiary.verificationStatus && badge(beneficiary.verificationStatus),
                beneficiary.possibleDuplicate && h("span", { className: "badge danger", title: "Review this record against existing resident profiles." }, "Possible duplicate")
              )
            ),
            h("div", { className: "beneficiary-profile-details" },
              h("div", null, h("span", null, "Address / Purok"), h("b", null, beneficiary.purok || "Not provided")),
              h("div", null, h("span", null, "Contact number"), h("b", null, beneficiary.contact || "Not provided")),
              h("div", null, h("span", null, "Last assistance"), h("b", null, beneficiary.lastAid || "No assistance recorded")),
              h("div", null, h("span", null, "Profile updated"), h("b", null, beneficiary.updated || "Date unavailable"))
            ),
            h("div", { className: "beneficiary-profile-assistance" },
              h("span", { className: "beneficiary-profile-label" }, "Assistance categories"),
              assistanceTypes.some((type) => type !== "None")
                ? h("div", { className: "beneficiary-category-list" }, assistanceTypes.filter((type) => type !== "None").map((type) => h("span", { className: "beneficiary-category-chip", key: type }, type)))
                : h("span", { className: "beneficiary-category-empty" }, "No assistance category on file")
            )
          );
        }))
        : h("div", { className: "beneficiary-directory-empty" },
          h("span", { className: "beneficiary-empty-icon", "aria-hidden": "true" }, h(Icon, { id: "search" })),
          h("b", null, visibleBeneficiaries.length ? "No matching residents" : "No beneficiary records yet"),
          h("p", null, visibleBeneficiaries.length ? "Try changing your search or filters to find a resident." : "Registered resident profiles will appear here when available."),
          hasFilters && h("button", { className: "secondary", type: "button", onClick: clearDirectoryFilters }, "Clear filters")
        ),
      h("p", { className: "beneficiary-results-note", "aria-live": "polite" }, `Showing ${rows.length} of ${visibleBeneficiaries.length} resident profiles`)
    )
  );
  return h("section", { className: "grid" },
    editable
      ? beneficiaryList
      : actions.session.role === "user"
        ? h("div", { className: "notice" }, "Assistance on this page is linked to the resident account with the same name and appears on that resident's My Assistance page.")
        : null,
    editable ? null : readOnlyDirectory
  );
}

async function toggleBeneficiaryStatus(actions, beneficiary) {
  const status = beneficiary.status === "Active" ? "Pending" : "Active";
  const beneficiaries = actions.data.beneficiaries.map((item) => item.id === beneficiary.id ? { ...item, status, updated: today() } : item);
  const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Updated beneficiary status for ${beneficiary.name} to ${status}`, { ...actions.data, beneficiaries }));
  if (saved) actions.notify("Beneficiary status updated.");
}

async function deleteBeneficiary(actions, beneficiary) {
  const linkedAid = actions.data.assistance.some((item) => item.beneficiary === beneficiary.name);
  const linkedUser = actions.data.users.some((user) => user.beneficiaryName === beneficiary.name);
  if (linkedAid || linkedUser) {
    actions.notify("Remove linked assistance records and resident accounts before deleting this beneficiary.");
    return;
  }
  if (!window.confirm(`Delete beneficiary ${beneficiary.name}?`)) return;
  const beneficiaries = actions.data.beneficiaries.filter((item) => item.id !== beneficiary.id);
  const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Deleted beneficiary ${beneficiary.name}`, { ...actions.data, beneficiaries }));
  if (saved) actions.notify("Beneficiary deleted.");
}

async function updateBeneficiaryVerification(actions, beneficiary, status) {
  try {
    await window.BarangayCareService.updateBeneficiaryVerification(beneficiary.id, status);
  } catch (error) {
    actions.notify(`Could not update beneficiary verification: ${error.message}`);
    return false;
  }

  try {
    await actions.refreshData();
  } catch (error) {
    actions.notify(`Verification was saved, but the resident list could not be refreshed: ${error.message}`);
    return false;
  }

  actions.notify(status === "Verified" ? "Beneficiary marked as verified." : "Beneficiary review reopened.");
  return true;
}
//#endregion
//#region Assistance review, distribution, and release history
function ResidentAssistance({ actions }) {
  return h("section", { className: "grid" },
    h(ResidentAssistanceUpdates, { actions }),
    h(Assistance, { actions, editable: false })
  );
}

function ResidentAssistanceUpdates({ actions }) {
  const [uploadingProofApplication, setUploadingProofApplication] = useState(null);
  const applications = actions.data.applications || [];

  async function uploadProof(event, application) {
    event.preventDefault();
    const form = event.currentTarget;
    const input = form.querySelector('input[type="file"]');
    const files = Array.from(input?.files || []);
    if (!files.length) {
      actions.notify("Choose at least one proof file to upload.");
      return;
    }
    if (files.length > 5) {
      actions.notify("You can upload up to 5 proof files at a time.");
      return;
    }
    if (files.some((file) => file.size > 10 * 1024 * 1024)) {
      actions.notify("Each proof file must be 10 MB or smaller.");
      return;
    }

    setUploadingProofApplication(application.id);
    try {
      await window.BarangayCareService.uploadAssistanceApplicationProof(application.id, files);
      await actions.refreshData();
      form.reset();
      actions.notify("Your proof documents were submitted securely for review.");
    } catch (error) {
      actions.notify(`Could not upload proof documents: ${error.message}`);
    } finally {
      setUploadingProofApplication(null);
    }
  }

  return h("section", { className: "card" },
    h("div", { className: "section-title" }, h("div", null, h("h2", null, "Assistance Updates"), h("p", { className: "section-note" }, "The barangay assigns assistance based on your registered benefits. You do not need to apply."))),
    applications.length
      ? h("div", { className: "list" }, applications.map((application) => {
        const distribution = (actions.data.distributions || []).find((item) => Number(item.applicationId) === Number(application.id));
        const program = actions.data.programs.find((item) => String(item.id) === String(application.programId));
        return h("article", { className: "list-item resident-application", key: application.id },
          h("b", null, `Assistance record #${application.id} · ${application.programName}`),
          h("span", null, `${application.benefit} · Recorded ${displayDate(application.submittedAt)}`),
          badge(residentApplicationStatus(application.status)),
          application.proofRequest && h("div", { className: "proof-request-panel resident-proof-request" },
            h("div", { className: "proof-request-heading" },
              h("b", null, "Eligibility proof requested"),
              badge(application.proofRequest.status)
            ),
            h("p", null, application.proofRequest.note),
            application.proofRequest.status === "Requested"
              ? h("form", { className: "proof-upload-form", onSubmit: (event) => uploadProof(event, application) },
                  h("label", { className: "field" },
                    h("span", null, "Upload valid ID or requested proof"),
                    h("input", {
                      className: "input",
                      type: "file",
                      name: "proofs[]",
                      accept: ".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf",
                      multiple: true,
                      required: true,
                    })
                  ),
                  h("p", { className: "proof-upload-help" }, "JPG, PNG, or PDF · Up to 5 files · 10 MB maximum per file. Only authorized Assistance Officers and Administrators can view these documents."),
                  h("button", {
                    className: "primary",
                    type: "submit",
                    disabled: uploadingProofApplication === application.id,
                  }, uploadingProofApplication === application.id ? "Uploading securely..." : "Submit proof")
                )
              : h("p", { className: "proof-upload-help" }, `Submitted ${displayDate(application.proofRequest.submittedAt)} · ${application.proofRequest.fileCount} file(s) received. The Assistance Officer will review your documents.`)
          ),
          application.status === "Approved" && h("span", null, "Approved — awaiting distribution. This does not mean the assistance has been received."),
          application.status === "Distributed" && distribution && h("span", null, `Received ${distribution.amount} on ${displayDate(distribution.distributedAt)}.`),
          application.reviewNote && h("span", null, `Staff note: ${application.reviewNote}`),
          program && application.status === "Approved" && h("span", null, `Schedule: ${scheduleDetails(program)}`),
          program?.instructions && application.status === "Approved" && h("span", null, `Instructions: ${program.instructions}`)
        );
      }))
      : h("div", { className: "empty compact" }, "No assistance has been recorded for your account yet. Barangay staff will update this page when assistance is assigned.")
  );
}

function AssistanceApplicationQueue({ actions }) {
  const role = actions.session.role;
  const canReview = role === "assistance_officer";
  const canViewApplications = ["admin", "assistance_officer", "barangay"].includes(role);
  const canViewProofs = ["admin", "assistance_officer"].includes(role);
  const applications = actions.data.applications || [];
  const distributions = actions.data.distributions || [];
  const [reviewNotes, setReviewNotes] = useState({});
  const [proofNotes, setProofNotes] = useState({});
  const [downloadingProof, setDownloadingProof] = useState(null);

  async function review(application, status) {
    const reviewNote = reviewNotes[application.id] || "";
    if (status === "Rejected" && !reviewNote.trim()) {
      actions.notify("A reason is required to reject an application.");
      return;
    }
    try {
      await window.BarangayCareService.reviewAssistanceApplication(application.id, status, reviewNote);
      await actions.refreshData();
      actions.notify(`Application ${status.toLowerCase()}.`);
    } catch (error) {
      actions.notify(`Could not update the application: ${error.message}`);
    }
  }

  async function requestProof(event, application) {
    event.preventDefault();
    const note = (proofNotes[application.id] || "").trim();
    if (!note) {
      actions.notify("Explain which proof documents the resident needs to provide.");
      return;
    }
    try {
      await window.BarangayCareService.requestAssistanceApplicationProof(application.id, note);
      await actions.refreshData();
      actions.notify("Proof request sent to the resident.");
    } catch (error) {
      actions.notify(`Could not request proof: ${error.message}`);
    }
  }

  async function downloadProof(application, file) {
    setDownloadingProof(file.id);
    try {
      const blob = await window.BarangayCareService.downloadAssistanceApplicationProof(application.id, file.id);
      const objectUrl = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      const safeName = String(file.name || "proof-document")
        .replace(/[\\/]/g, "/")
        .split("/")
        .pop()
        .replace(/[^A-Za-z0-9._-]/g, "_");
      link.download = safeName && safeName !== "." && safeName !== ".." ? safeName : "proof-document";
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => window.URL.revokeObjectURL(objectUrl), 1000);
    } catch (error) {
      actions.notify(`Could not download proof: ${error.message}`);
    } finally {
      setDownloadingProof(null);
    }
  }

  const applicationStages = [
    { status: "Submitted", label: "Submitted" },
    { status: "Under Review", label: "Under review" },
    { status: "Approved", label: "Approved" },
    { status: "Distributed", label: "Distributed" },
    { status: "Rejected", label: "Declined" },
  ];
  const isAdminMonitor = role === "admin";

  return h("section", { className: `card assistance-application-queue ${isAdminMonitor ? "admin-assistance-queue" : ""}` },
    h("div", { className: "section-title" },
      h("div", null,
        h("span", { className: "assistance-queue-eyebrow" }, isAdminMonitor ? "REQUEST MONITORING" : "PROGRAM WORKFLOW"),
        h("h2", null, canReview ? "Application Review" : "Assistance Applications"),
        h("p", { className: "section-note" }, canReview
          ? "Review resident requests before approving or declining them."
          : isAdminMonitor
            ? "Track historical resident requests through review and distribution. Application decisions are handled by authorized staff."
            : "View historical application progress and refer review decisions to an authorized assistance officer.")
      ),
      h("span", { className: "badge" }, `${applications.length} applications`)
    ),
    isAdminMonitor && h("div", { className: "assistance-application-stages", "aria-label": "Application status summary" },
      applicationStages.map(({ status, label }) => {
        const count = applications.filter((application) => application.status === status).length;
        return h("div", { className: `assistance-stage assistance-stage-${status.toLowerCase().replaceAll(" ", "-")}`, key: status },
          h("span", null, label),
          h("b", null, count)
        );
      })
    ),
    canViewApplications && applications.length
      ? h("div", { className: `grid assistance-application-list ${isAdminMonitor ? "admin-assistance-application-list" : ""}` }, applications.map((application) => h("article", { className: "list-item", key: application.id },
          h("b", null, application.beneficiary),
          h("span", null, `${application.programName} · ${application.benefit}`),
          h("span", null, `Status: ${application.status}`),
          application.reason && h("span", null, `Reason: ${application.reason}`),
          application.reviewNote && h("span", null, `Staff note: ${application.reviewNote}`),
          canReview && application.status === "Under Review" && !application.proofRequest && h("form", {
            className: "proof-request-form",
            onSubmit: (event) => requestProof(event, application),
          },
            h("label", { className: "field" },
              h("span", null, "Request eligibility proof"),
              h("textarea", {
                className: "input",
                rows: 2,
                maxLength: 1000,
                value: proofNotes[application.id] || "",
                onChange: (event) => setProofNotes((current) => ({ ...current, [application.id]: event.target.value })),
                placeholder: "Specify the valid ID or eligibility document the resident must provide.",
              })
            ),
            h("button", { className: "small-action", type: "submit" }, "Request proof from resident")
          ),
          canViewProofs && application.proofRequest && h("div", { className: "proof-request-panel" },
            h("div", { className: "proof-request-heading" },
              h("b", null, "Eligibility proof"),
              badge(application.proofRequest.status)
            ),
            h("p", null, application.proofRequest.note),
            application.proofRequest.status === "Requested"
              ? h("p", { className: "proof-upload-help" }, "Waiting for the resident to upload the requested documents.")
              : h("div", { className: "proof-file-list" },
                  (application.proofRequest.files || []).map((file) => h("div", { className: "proof-file-row", key: file.id },
                    h("span", null, `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`),
                    h("button", {
                      className: "small-action",
                      type: "button",
                      disabled: downloadingProof === file.id,
                      onClick: () => downloadProof(application, file),
                    }, downloadingProof === file.id ? "Preparing..." : "Download securely")
                  )),
                  h("p", { className: "proof-upload-help" }, "This document is private and can only be opened by an Assistance Officer or Administrator. Administrators have read-only access. It is deleted after rejection or benefit distribution.")
                )
          ),
          canReview && application.status === "Submitted" && h("div", { className: "row-actions" },
            h("button", { className: "small-action", type: "button", onClick: () => review(application, "Under Review") }, "Start Review")
          ),
          canReview && application.status === "Under Review" && h("div", { className: "grid" },
            h("label", { className: "field" }, h("span", null, "Review note (required for rejection)"), h("textarea", { className: "input", rows: 2, maxLength: 1000, value: reviewNotes[application.id] || "", onChange: (event) => setReviewNotes((current) => ({ ...current, [application.id]: event.target.value })) })),
            h("div", { className: "row-actions" },
              h("button", {
                className: "small-action",
                type: "button",
                disabled: application.proofRequest?.status === "Requested",
                onClick: () => review(application, "Approved"),
              }, application.proofRequest?.status === "Requested" ? "Awaiting proof" : "Approve"),
              h("button", { className: "small-action danger", type: "button", onClick: () => review(application, "Rejected") }, "Reject")
            )
          ),
        )))
      : h("div", { className: `empty compact assistance-application-empty ${isAdminMonitor ? "admin-assistance-empty" : ""}` },
        isAdminMonitor
          ? h(React.Fragment, null,
            h("span", { className: "assistance-empty-mark", "aria-hidden": "true" }, h(Icon, { id: "report" })),
            h("b", null, "No applications yet"),
            h("p", null, "New resident requests will appear here as soon as they are submitted.")
          )
          : "No applications have been submitted yet. New requests will appear here for review."
      ),
    distributions.length > 0 && h("div", null,
      h("h3", { className: "assistance-distribution-title" }, "Distribution history"),
      h(Table, { headers: ["Beneficiary", "Benefit", "Amount", "Distributed By", "Date"], rows: distributions.map((record) => [record.beneficiary, record.benefit, record.amount, record.distributedBy, record.distributedAt]) })
    )
  );
}

function DistributionAssistanceDesk({ actions }) {
  const assistance = actions.data.assistance || [];
  const beneficiariesByName = new Map((actions.data.beneficiaries || []).map((beneficiary) => [
    String(beneficiary.name || "").trim().toLowerCase(),
    beneficiary,
  ]));
  const availablePrograms = (actions.data.programs || []).filter((program) =>
    ["scheduled", "open"].includes(String(program.status || "").trim().toLowerCase())
  );
  const distributionsByAssistance = new Map((actions.data.distributions || [])
    .filter((distribution) => distribution.assistanceId)
    .map((distribution) => [String(distribution.assistanceId), distribution]));
  const [selectedPrograms, setSelectedPrograms] = useState({});
  const [amounts, setAmounts] = useState({});
  const [filter, setFilter] = useState("Ready to claim");
  const [savingId, setSavingId] = useState(null);
  const normalizedQuery = actions.query.trim().toLowerCase();
  const claimableRecords = assistance.filter((record) => {
    const beneficiary = beneficiariesByName.get(String(record.beneficiary || "").trim().toLowerCase());
    return beneficiary
      && String(beneficiary.status || "").toLowerCase() === "active"
      && ["Pending", "Completed"].includes(record.status);
  });
  const claimedRecords = assistance.filter((record) => record.status === "Claimed");
  const recordsNeedingProgram = claimableRecords.filter((record) => eligibleProgramsFor(record).length === 0);

  const records = assistance
    .filter((record) => {
      const isClaimed = record.status === "Claimed";
      if (filter === "Ready to claim" && !claimableRecords.includes(record)) return false;
      if (filter === "Claimed" && !isClaimed) return false;

      const distribution = distributionsByAssistance.get(String(record.id));
      const searchable = [
        record.id,
        record.beneficiary,
        record.type,
        record.amount,
        distribution?.distributedBy,
      ].join(" ").toLowerCase();

      return searchable.includes(normalizedQuery);
    })
    .sort((first, second) => String(first.beneficiary || "").localeCompare(String(second.beneficiary || "")));

  function eligibleProgramsFor(record) {
    const beneficiary = beneficiariesByName.get(String(record.beneficiary || "").trim().toLowerCase());
    const categories = categoryList(beneficiary?.category).map((category) => category.toLowerCase());

    return availablePrograms.filter((program) => {
      const benefit = String(program.benefit || "").trim().toLowerCase();
      return benefit === "all residents" || categories.includes(benefit);
    });
  }

  function selectedProgramFor(record, eligiblePrograms) {
    const selectedId = selectedPrograms[record.id];
    if (selectedId === "") return null;
    return eligiblePrograms.find((program) => String(program.id) === String(selectedId))
      || eligiblePrograms[0]
      || null;
  }

  function amountFor(record, program) {
    const assignedAmount = String(record.amount || "").trim();
    const initialAmount = assignedAmount && assignedAmount.toLowerCase() !== "as released"
      ? assignedAmount
      : String(program?.releaseAmount || "").trim();

    return amounts[record.id] ?? initialAmount;
  }

  async function recordClaim(record, program, amount) {
    if (!amount) {
      actions.notify("Enter the actual amount or items handed over before recording the claim.");
      return;
    }
    const releaseDescription = program
      ? `for ${program.name}`
      : `for the scheduled ${record.type} release on ${displayDate(record.date)}`;
    if (!window.confirm(`Confirm that ${record.beneficiary} has received ${amount} ${releaseDescription}?`)) return;

    setSavingId(record.id);
    try {
      await window.BarangayCareService.claimAssistance(record.id, {
        ...(program ? { programId: program.id } : {}),
        amount,
      });
      await actions.refreshData();
      actions.notify("Assistance claim recorded successfully.");
    } catch (error) {
      actions.notify(`Could not record the assistance claim: ${error.message}`);
    } finally {
      setSavingId(null);
    }
  }

  return h("section", { className: "distribution-claim-desk card", "aria-label": "Assistance claim desk" },
    h("div", { className: "distribution-claim-heading" },
      h("div", null,
        h("h2", null, "Record resident claims"),
        h("p", { className: "section-note" }, "Only active, eligible residents with pending or completed assistance are ready to claim.")
      ),
      h("span", { className: "badge" }, `${claimableRecords.length} ready`)
    ),
    h("div", { className: "notice distribution-claim-handover-rule" }, "Only record the handover after the resident has received the cash or items."),
    h("div", { className: "distribution-claim-summary" },
      h("div", null, h("b", null, claimableRecords.length), h("span", null, "Ready to claim")),
      h("div", null, h("b", null, claimedRecords.length), h("span", null, "Claims recorded")),
      h("div", null, h("b", null, recordsNeedingProgram.length), h("span", null, "Use resident schedule"))
    ),
    h("div", { className: "toolbar distribution-claim-toolbar" },
      h(SearchBar, {
        query: actions.query,
        setQuery: actions.setQuery,
        placeholder: "Search resident, assistance, or record ID",
      }),
      h(FilterSelect, {
        label: "Claim status",
        value: filter,
        onChange: setFilter,
        options: ["Ready to claim", "Claimed", "All records"],
      }),
      h("span", { className: "badge" }, `${records.length} ${records.length === 1 ? "record" : "records"}`)
    ),
    records.length
      ? h("div", { className: "distribution-claim-list" }, records.map((record) => {
        const eligiblePrograms = eligibleProgramsFor(record);
        const program = selectedProgramFor(record, eligiblePrograms);
        const distribution = distributionsByAssistance.get(String(record.id));
        const amount = amountFor(record, program);
        const isClaimed = record.status === "Claimed";
        const isReadyToClaim = claimableRecords.includes(record);

        return h("article", { className: "distribution-claim-card", key: record.id },
          h("div", { className: "distribution-claim-card-heading" },
            h("div", null,
              h("span", { className: "program-monitor-kicker" }, `RECORD ${record.id}`),
              h("h3", null, record.beneficiary),
              h("p", { className: "section-note" }, `${record.type} · Assistance date ${displayDate(record.date)}`)
            ),
            badge(isClaimed ? "Claimed" : record.status)
          ),
          isClaimed
            ? h("div", { className: "distribution-claim-receipt" },
              h("span", null, h("b", null, "Amount / Items: "), distribution?.amount || record.amount || "Not recorded"),
              h("small", null, distribution
                ? `${distribution.programId ? `${distribution.programId} · ` : ""}${distribution.distributedBy} · ${displayDate(distribution.distributedAt)}`
                : `Claimed by ${record.claimedBy || "staff"} · ${displayDate(record.claimedAt)}`)
            )
            : !isReadyToClaim
              ? h("div", { className: "distribution-claim-receipt" },
                h("span", null, "This record is not ready for handover."),
                h("small", null, `Current status: ${record.status}`)
              )
              : h("div", { className: "distribution-claim-form" },
                eligiblePrograms.length
                  ? h("label", { className: "field" },
                    h("span", null, "Program link (optional)"),
                    h("select", {
                      className: "select",
                      value: String(program?.id || ""),
                      onChange: (event) => {
                        setSelectedPrograms((current) => ({ ...current, [record.id]: event.target.value }));
                        setAmounts((current) => {
                          const next = { ...current };
                          delete next[record.id];
                          return next;
                        });
                      },
                      "aria-label": `Optional program link for ${record.beneficiary}`,
                    },
                    h("option", { value: "" }, "Resident assignment schedule only"),
                    eligiblePrograms.map((option) => h("option", { value: option.id, key: option.id }, `${option.name} · ${option.benefit}`))
                    )
                  )
                  : h("div", { className: "notice distribution-claim-no-program" }, "This release will be recorded against the resident's assignment schedule without a program link."),
                h("label", { className: "field" },
                  h("span", null, "Actual amount / items handed over"),
                  h("input", {
                    className: "input",
                    maxLength: 120,
                    value: amount,
                    placeholder: "Enter the actual cash amount or item quantity",
                    onChange: (event) => setAmounts((current) => ({ ...current, [record.id]: event.target.value })),
                    "aria-label": `Actual amount or items for ${record.beneficiary}`,
                  })
                ),
                h("div", { className: "distribution-claim-action" },
                  h("small", { className: "muted" }, `Assigned amount: ${record.amount || "Not specified"}`),
                  h("button", {
                    className: "primary",
                    type: "button",
                    disabled: savingId === record.id || !String(amount).trim(),
                    onClick: () => recordClaim(record, program, String(amount).trim()),
                  }, savingId === record.id ? "Recording claim..." : "Confirm handover")
                )
              )
        );
      }))
      : h("div", { className: "empty compact distribution-claim-empty" },
        filter === "Claimed"
          ? "No recorded claims match your search."
          : filter === "Ready to claim"
            ? "No active resident assistance is waiting to be claimed."
            : "No assistance records match your search."
      )
  );
}

function Assistance({ actions, editable }) {
  const [status, setStatus] = useState("All");
  const visibleAssistance = editable ? actions.data.assistance : scopedAssistance(actions);
  const normalizedQuery = actions.query.trim().toLowerCase();
  const rows = useMemo(() => visibleAssistance.filter((item) => JSON.stringify(item).toLowerCase().includes(normalizedQuery) && (status === "All" || item.status === status)), [visibleAssistance, normalizedQuery, status]);
  const claimedCount = visibleAssistance.filter((item) => item.status === "Claimed").length;
  const pendingCount = visibleAssistance.filter((item) => item.status === "Pending").length;
  const completedCount = visibleAssistance.filter((item) => item.status === "Completed").length;
  const hasFilters = Boolean(normalizedQuery) || status !== "All";

  async function deleteAssistance(record) {
    if (!window.confirm(`Delete ${record.type} for ${record.beneficiary}?`)) return;
    const assistance = actions.data.assistance.filter((item) => item.id !== record.id);
    const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Deleted ${record.type} for ${record.beneficiary}`, { ...actions.data, assistance }));
    if (saved) actions.notify("Assistance record deleted.");
  }

  const headers = ["Record", "Beneficiary", "Type", "Planned / Actual Amount", "Date", "Status", "Claimed Date", "Claimed By", "Staff", "Action"];
  const tableRows = rows.map((a) => {
    const displayStatus = actions.session.role === "user"
      ? a.status === "Claimed" ? "Received" : "Assigned"
      : a.status;
    const base = [a.id, a.beneficiary, a.type, a.amount, a.date, badge(displayStatus), a.claimedAt || "-", a.claimedBy || "-", a.staff];
    if (!editable) {
      if (String(a.id).startsWith("selected-")) return [...base, h("span", { className: "badge warn" }, "Selected")];
      if (a.status === "Claimed") return [...base, h("span", { className: "badge ok" }, "Already Claimed")];
      return [...base, h("span", { className: "badge" }, "View only")];
    }
    return [
      ...base,
      h("div", { className: "row-actions" },
        a.status === "Claimed"
          ? h("span", { className: "badge ok" }, "Claimed")
          : h("span", { className: "badge" }, "Assigned"),
        a.status !== "Claimed" && h("button", {
          className: "small-action",
          type: "button",
          onClick: () => actions.setModal({ type: "assistanceScheduleEdit", record: a }),
        }, "Edit schedule"),
        h("button", { className: "small-action danger", onClick: () => deleteAssistance(a) }, "Delete")
      ),
    ];
  });

  const residentBenefits = !editable ? [...new Set(visibleAssistance.map((item) => item.type).filter(Boolean))] : [];
  const assistanceNote = actions.session.role === "user"
    ? residentBenefits.length ? `Your assistance includes ${residentBenefits.join(", ")}. Each benefit selected for your account is listed below.` : "Benefits selected for your account will appear here."
    : actions.session.role === "admin"
      ? "Monitoring view: review assistance records and application progress. Claim and review actions are managed by authorized staff."
    : actions.session.role === "assistance_officer"
      ? "Secretary staff assign residents and planned amounts. Review applications here; distribution staff record completed handovers."
    : actions.session.role === "distribution_staff"
        ? "Select a scheduled program to review eligible residents and record each handover as a program-linked release transaction."
        : "Review scheduled assistance and record a claim when it is handed to a beneficiary.";
  const canManageApplications = ["admin", "assistance_officer", "distribution_staff", "barangay"].includes(actions.session.role);
  const isAdminMonitor = actions.session.role === "admin";
  const isDistributionStaff = actions.session.role === "distribution_staff";
  const canRecordAssistance = editable && actions.session.role !== "secretary";
  if (isDistributionStaff) {
    return h("section", { className: "grid assistance-page distribution-assistance-page" },
      h(DistributionPageIntro, {
        icon: "aid",
        section: "Social assistance",
        title: "Assistance claim desk",
        description: "Review each resident's assigned release date, confirm the actual handover, and optionally link it to a program.",
        detail: "Claim transactions",
      }),
      h(DistributionAssistanceDesk, { actions })
    );
  }

  return h("section", { className: `grid assistance-page ${isAdminMonitor ? "admin-assistance-page" : ""}` },
    isAdminMonitor && h("section", { className: "admin-assistance-hero" },
      h("div", null,
        h("span", { className: "admin-assistance-eyebrow" }, h(Icon, { id: "aid" }), " BARANGAYCARE · SERVICE MONITORING"),
        h("h2", null, "Social assistance overview"),
        h("p", null, "Monitor resident requests, assistance claims, and completed releases in one place.")
      ),
      h("div", { className: "admin-assistance-access" },
        h("span", { className: "beneficiary-access-mark", "aria-hidden": "true" }, h(Icon, { id: "lock" })),
        h("span", null, h("b", null, "Monitoring access"), h("small", null, "Changes are managed by authorized staff"))
      )
    ),
    canManageApplications && h(AssistanceApplicationQueue, { actions }),
    !editable && !isAdminMonitor && h("div", { className: "notice" }, assistanceNote),
    h("section", { className: "assistance-overview" },
      h("div", { className: "section-title" },
        h("div", null, h("h2", null, isAdminMonitor ? "Recorded assistance" : "Assistance overview"), h("p", { className: "section-note" }, isAdminMonitor ? "Current status of recorded assistance benefits." : "A clear snapshot of recorded assistance and its current status.")),
        h("span", { className: "badge" }, `${visibleAssistance.length} total records`)
      ),
      h("div", { className: "claim-summary" },
        h("button", { className: `claim-card total assistance-filter-card ${status === "All" ? "selected" : ""}`, type: "button", "aria-pressed": status === "All", onClick: () => setStatus("All") }, h("b", null, visibleAssistance.length), h("span", null, "Total records"), h("small", null, "View all records")),
        h("button", { className: `claim-card pending assistance-filter-card ${status === "Pending" ? "selected" : ""}`, type: "button", "aria-pressed": status === "Pending", onClick: () => setStatus("Pending") }, h("b", null, pendingCount), h("span", null, "Pending"), h("small", null, "Awaiting follow-up")),
        h("button", { className: `claim-card claimed assistance-filter-card ${status === "Claimed" ? "selected" : ""}`, type: "button", "aria-pressed": status === "Claimed", onClick: () => setStatus("Claimed") }, h("b", null, claimedCount), h("span", null, "Claimed"), h("small", null, "Received by residents")),
        h("button", { className: `claim-card released assistance-filter-card ${status === "Completed" ? "selected" : ""}`, type: "button", "aria-pressed": status === "Completed", onClick: () => setStatus("Completed") }, h("b", null, completedCount), h("span", null, "Ready for handover"), h("small", null, "Awaiting distribution staff"))
      )
    ),
    h("section", { className: "card assistance-records-panel" },
      h("div", { className: "section-title assistance-records-heading" },
        h("div", null,
          h("h2", null, actions.session.role === "user" ? "My Assistance Records" : "Assistance records"),
          h("p", { className: "section-note" }, editable
            ? "Search and review each resident benefit, claim, and release."
            : isAdminMonitor
              ? "Search beneficiary, benefit type, record ID, or staff member."
              : "Review the assistance recorded for your account.")
        ),
        canRecordAssistance && h("button", { className: "primary", type: "button", onClick: () => actions.setModal("assistance") }, h(Icon, { id: "aid" }), " Record Assistance")
      ),
      editable && h("div", { className: "notice assistance-allocation-note" },
        "Set each resident's planned amount or items here. Distribution staff will see that resident-specific amount first; the program amount is only used when no individual amount is set."
      ),
      h("div", { className: "toolbar assistance-records-toolbar" },
        h(SearchBar, { query: actions.query, setQuery: actions.setQuery, placeholder: "Search by resident, assistance, or record ID" }),
        h(FilterSelect, { label: "Status", value: status, onChange: setStatus, options: ["All", "Completed", "Pending", "Claimed"] }),
        hasFilters && h("button", {
          className: "secondary assistance-clear-filters",
          type: "button",
          onClick: () => {
            actions.setQuery("");
            setStatus("All");
          },
        }, "Clear filters"),
        h("span", { className: "badge assistance-results-count" }, `Showing ${rows.length} of ${visibleAssistance.length} records`)
      ),
      h(Table, { headers, rows: tableRows, empty: visibleAssistance.length ? "No assistance records match your search and status filter." : "No assistance records are available yet." })
    ),
  );
}

function ProgramReleaseTransactions({
  actions,
  selectedProgramId,
  rosterRef,
}) {
  const programs = actions.data.programs || [];
  const assistance = actions.data.assistance || [];
  const distributions = actions.data.distributions || [];
  const [amounts, setAmounts] = useState({});
  const [savingId, setSavingId] = useState(null);
  const [releaseFilter, setReleaseFilter] = useState("All eligible residents");
  const [activeResidentIndex, setActiveResidentIndex] = useState(0);
  const selectedProgram = programs.find((program) => String(program.id) === selectedProgramId) || programs[0];

  useEffect(() => {
    setReleaseFilter("All eligible residents");
    setActiveResidentIndex(0);
  }, [selectedProgramId]);

  useEffect(() => {
    setActiveResidentIndex(0);
  }, [releaseFilter, actions.query]);

  if (!selectedProgram) {
    return h("section", { className: "card program-monitor", "aria-label": "Program release transactions" },
      h("div", { className: "section-title" }, h("h2", null, "Program release transactions")),
      h("div", { className: "empty compact" }, "No program schedules are available. Ask the Secretary to create a schedule before recording releases.")
    );
  }

  const isAllResidentsProgram = String(selectedProgram.benefit || "").trim().toLowerCase() === "all residents";
  const eligibleResidents = (actions.data.beneficiaries || []).filter((beneficiary) =>
    String(beneficiary.status || "").toLowerCase() === "active"
    && (isAllResidentsProgram || categoryList(beneficiary.category).some((category) =>
      category.toLowerCase() === String(selectedProgram.benefit || "").trim().toLowerCase()
    ))
  );
  const distributionsByAssistance = new Map(distributions
    .filter((distribution) => distribution.assistanceId)
    .map((distribution) => [String(distribution.assistanceId), distribution]));
  const assistanceByResident = new Map();
  assistance.forEach((record) => {
    const key = String(record.beneficiary || "").trim().toLowerCase();
    const records = assistanceByResident.get(key) || [];
    records.push(record);
    assistanceByResident.set(key, records);
  });
  const residentRoster = eligibleResidents
    .slice()
    .sort((first, second) => String(first.name || "").localeCompare(String(second.name || "")))
    .map((beneficiary) => {
      const key = String(beneficiary.name || "").trim().toLowerCase();
      const matchedBenefits = isAllResidentsProgram
        ? ["All residents"]
        : categoryList(beneficiary.category).filter((category) =>
          category.toLowerCase() === String(selectedProgram.benefit || "").trim().toLowerCase()
        );
      const records = (assistanceByResident.get(key) || []).map((record) => {
        const distribution = distributionsByAssistance.get(String(record.id)) || null;

        return {
          record,
          distribution,
          canRecord: !distribution && ["Pending", "Completed"].includes(record.status),
        };
      });

      return { beneficiary, matchedBenefits, records };
    });
  const programTransactions = distributions
    .filter((distribution) => String(distribution.programId || "") === String(selectedProgram.id))
    .slice()
    .sort((first, second) => String(second.distributedAt || "").localeCompare(String(first.distributedAt || "")));
  const releasedCount = residentRoster.filter((resident) =>
    resident.records.some(({ distribution }) => String(distribution?.programId || "") === String(selectedProgram.id))
  ).length;
  const awaitingReleaseCount = residentRoster.filter((resident) =>
    resident.records.some(({ canRecord }) => canRecord)
  ).length;
  const noAssistanceRecordCount = residentRoster.filter((resident) => resident.records.length === 0).length;
  const canRelease = selectedProgram.status !== "Completed";
  const releaseProgress = eligibleResidents.length
    ? Math.round((releasedCount / eligibleResidents.length) * 100)
    : 0;
  const normalizedSearch = actions.query.trim().toLowerCase();
  const visibleResidents = residentRoster.filter(({ beneficiary, records }) => {
    const assistanceSearch = records.map(({ record }) => `${record.type} ${record.id}`).join(" ");
    const matchesSearch = `${beneficiary.name} ${beneficiary.purok || ""} ${beneficiary.category || ""} ${assistanceSearch}`
      .toLowerCase()
      .includes(normalizedSearch);
    const matchesStatus = releaseFilter === "All eligible residents"
      || (releaseFilter === "Awaiting release" && records.some(({ canRecord }) => canRecord))
      || (releaseFilter === "Released" && records.some(({ distribution }) => String(distribution?.programId || "") === String(selectedProgram.id)))
      || (releaseFilter === "No assistance record" && records.length === 0);

    return matchesSearch && matchesStatus;
  });
  const displayedResidentIndex = Math.min(activeResidentIndex, Math.max(visibleResidents.length - 1, 0));

  function navigateResident(direction) {
    if (visibleResidents.length === 0) return;

    const nextIndex = Math.max(0, Math.min(visibleResidents.length - 1, displayedResidentIndex + direction));
    setActiveResidentIndex(nextIndex);
    window.requestAnimationFrame(() => {
      const residentRow = rosterRef.current?.querySelector(`[data-resident-index="${nextIndex}"]`);
      residentRow?.scrollIntoView({ behavior: "smooth", block: "center" });
      residentRow?.focus({ preventScroll: true });
    });
  }

  async function recordRelease(record) {
    const savedAmount = String(record.amount || "").trim();
    const assignedAmount = savedAmount && savedAmount.toLowerCase() !== "as released" ? savedAmount : "";
    const scheduleAmount = String(selectedProgram.releaseAmount || "").trim();
    const defaultAmount = assignedAmount || scheduleAmount;
    const amount = String(amounts[record.id] ?? defaultAmount).trim();
    if (!amount) {
      actions.notify("Enter the cash amount or items handed over before recording the release.");
      return;
    }
    if (!window.confirm(`Confirm that ${record.beneficiary} has received ${amount} for ${selectedProgram.name}? This will create a permanent transaction record.`)) return;

    setSavingId(record.id);
    try {
      await window.BarangayCareService.claimAssistance(record.id, {
        programId: selectedProgram.id,
        amount,
      });
      await actions.refreshData();
      actions.notify("Program release transaction recorded.");
    } catch (error) {
      actions.notify(`Could not record the program release: ${error.message}`);
    } finally {
      setSavingId(null);
    }
  }

  return h("section", { className: "card program-monitor", "aria-label": "Program release transactions" },
    h("div", { className: "section-title program-monitor-heading" },
      h("div", null,
        h("span", { className: "program-monitor-kicker" }, `SCHEDULE ${selectedProgram.id}`),
        h("h2", null, "Resident roster & handover"),
        h("p", { className: "section-note" }, "Verify the resident and record the cash or items only after they have been physically received. Each completed handover is saved with the schedule, date, and staff account.")
      )
    ),
    h("div", { className: "program-monitor-summary" },
      h("div", { className: "program-monitor-detail" },
        h("b", null, selectedProgram.name),
        h("span", null, `${selectedProgram.benefit || "No benefit category"} · ${scheduleDetails(selectedProgram)}`),
        h("span", null, `${selectedProgram.venue || "Venue not set"} · ${selectedProgram.status}`),
        selectedProgram.releaseAmount && h("span", null, `Amount / Items: ${selectedProgram.releaseAmount}`),
        h("span", null, `${eligibleResidents.length} active residents match this program's benefit`)
      ),
      h("div", { className: "program-monitor-stages" },
        [
          ["Eligible residents", eligibleResidents.length],
          ["Released", releasedCount],
          ["Awaiting handover", awaitingReleaseCount],
          ["No assistance record", noAssistanceRecordCount],
        ].map(([label, count]) => h("div", { className: "program-monitor-stage", key: label },
          h("b", null, count),
          h("span", null, label)
        ))
      )
    ),
    h("div", { className: "program-release-progress" },
      h("div", { className: "program-release-progress-heading" },
        h("div", null,
          h("b", null, "Program release progress"),
          h("span", { className: "muted" }, `${releasedCount} of ${eligibleResidents.length} eligible residents have a recorded release`)
        ),
        h("strong", null, `${releaseProgress}%`)
      ),
      h("div", {
        className: "program-release-progress-track",
        role: "progressbar",
        "aria-label": "Program release progress",
        "aria-valuemin": 0,
        "aria-valuemax": 100,
        "aria-valuenow": releaseProgress,
      }, h("span", { style: { width: `${releaseProgress}%` } }))
    ),
    !canRelease && h("div", { className: "notice" }, "This program is completed. Existing transactions remain available for review, but no additional releases can be recorded."),
    canRelease && h("div", { className: "program-release-workflow", "aria-label": "Handover steps" },
      [
        ["01", "Verify resident", "Confirm the resident's name and eligibility."],
        ["02", "Enter handover", "Record the actual cash amount or item quantity."],
        ["03", "Save transaction", "Confirm once the handover is complete."],
      ].map(([number, title, description]) => h("div", { className: "program-release-workflow-step", key: number },
        h("span", null, number),
        h("div", null, h("b", null, title), h("small", null, description))
      ))
    ),
    h("section", {
      ref: rosterRef,
      className: "program-resident-roster",
      tabIndex: -1,
      "aria-labelledby": "program-roster-title",
    },
    h("div", { className: "program-resident-roster-heading" },
      h("div", null,
        h("span", { className: "program-monitor-kicker" }, `ELIGIBLE FOR ${selectedProgram.benefit || "THIS PROGRAM"}`),
        h("h3", { id: "program-roster-title" }, "Eligible resident list"),
        h("p", { className: "section-note" }, `Active residents matched by the selected benefit: ${selectedProgram.benefit || "No benefit selected"}.`)
      ),
      h("div", { className: "program-resident-navigation", "aria-label": "Navigate eligible residents" },
        h("span", { className: "muted", "aria-live": "polite" }, visibleResidents.length
          ? `Resident ${displayedResidentIndex + 1} of ${visibleResidents.length}`
          : "No residents"),
        h("button", {
          className: "small-action",
          type: "button",
          disabled: displayedResidentIndex <= 0 || visibleResidents.length === 0,
          onClick: () => navigateResident(-1),
          "aria-label": "Previous resident",
        }, "↑ Previous"),
        h("button", {
          className: "small-action",
          type: "button",
          disabled: displayedResidentIndex >= visibleResidents.length - 1 || visibleResidents.length === 0,
          onClick: () => navigateResident(1),
          "aria-label": "Next resident",
        }, "Next ↓")
      )
    ),
    h("div", { className: "toolbar program-release-toolbar" },
      h(SearchBar, {
        query: actions.query,
        setQuery: actions.setQuery,
        placeholder: "Search eligible residents, purok, benefit, or record ID",
      }),
      h(FilterSelect, {
        label: "Program roster status",
        value: releaseFilter,
        onChange: setReleaseFilter,
        options: ["All eligible residents", "Awaiting release", "Released", "No assistance record"],
      }),
      h("span", { className: "badge program-release-results" }, `Showing ${visibleResidents.length} of ${eligibleResidents.length} residents`)
    ),
    h(Table, {
      className: "program-resident-roster-table",
      rowAttributes: (_row, index) => ({
        "data-resident-index": index,
        tabIndex: -1,
      }),
      headers: ["Eligible resident", "Purok", "Program benefit", "Assistance records and handover"],
      rows: visibleResidents.map(({ beneficiary, matchedBenefits, records }) => [
        h("div", { className: "program-roster-resident" },
          h("b", null, beneficiary.name),
          h("small", { className: "muted" }, `${records.length} ${records.length === 1 ? "assistance record" : "assistance records"}`)
        ),
        beneficiary.purok || "-",
        h("div", { className: "program-roster-benefit" },
          matchedBenefits.map((category) => badge(category)),
          h("small", { className: "muted" }, "Matched from resident profile")
        ),
        records.length
          ? h("div", { className: "program-roster-records" }, records.map(({ record, distribution, canRecord: canRecordThis }) => {
              const savedAmount = String(record.amount || "").trim();
              const assignedAmount = savedAmount && savedAmount.toLowerCase() !== "as released" ? savedAmount : "";
              const scheduleAmount = String(selectedProgram.releaseAmount || "").trim();
              const defaultAmount = assignedAmount || scheduleAmount;
              const belongsToSelectedProgram = String(distribution?.programId || "") === String(selectedProgram.id);
              const belongsToAnotherProgram = distribution?.programId && !belongsToSelectedProgram;
            const hasUnlinkedDistribution = distribution && !distribution.programId;
            const actionDisabled = !canRelease || savingId === record.id || !canRecordThis;

            return h("div", { className: "program-roster-record", key: record.id },
              h("div", { className: "program-roster-record-heading" },
                h("div", null,
                  h("b", null, record.type),
                  h("small", { className: "muted" }, `Record ${record.id}`)
                ),
                belongsToSelectedProgram
                  ? badge("Released")
                  : belongsToAnotherProgram
                    ? badge("Recorded elsewhere")
                    : hasUnlinkedDistribution
                      ? badge("Previously recorded")
                    : record.status === "Claimed"
                      ? badge("Previously claimed")
                      : canRecordThis
                        ? badge(record.status === "Pending" ? "Awaiting release" : "Ready to release")
                        : badge(record.status || "Needs review")
              ),
              belongsToSelectedProgram
                ? h("div", { className: "program-release-transaction-status" },
                  h("span", null, `${distribution.amount} · REL-${distribution.id}`),
                  h("small", { className: "muted" }, `${displayDate(distribution.distributedAt)} · ${distribution.distributedBy}`)
                )
                : belongsToAnotherProgram
                  ? h("small", { className: "muted" }, "This record is already linked to another program.")
                  : hasUnlinkedDistribution
                    ? h("small", { className: "muted" }, "This record already has a handover transaction without a program link.")
                  : record.status === "Claimed"
                    ? h("small", { className: "muted" }, "This record was claimed previously and cannot be released again.")
                    : canRecordThis
                      ? h("div", { className: "program-roster-release-action" },
                        h("small", { className: "muted" }, assignedAmount
                          ? `Resident allocation: ${assignedAmount}. Confirm or adjust to match the actual handover.`
                          : scheduleAmount
                            ? `No resident-specific amount is set. Program amount: ${scheduleAmount}. Confirm or adjust to match the actual handover.`
                            : "No amount is assigned yet. Enter the actual cash or items handed over."
                        ),
                      h("input", {
                        className: "input",
                        maxLength: 120,
                        value: amounts[record.id] ?? defaultAmount,
                        placeholder: "Actual amount or items handed over",
                        disabled: actionDisabled,
                        "aria-label": `Cash amount or items handed over to ${beneficiary.name} for ${record.type}`,
                        onChange: (event) => setAmounts((current) => ({ ...current, [record.id]: event.target.value })),
                      }),
                      h("button", {
                        className: "small-action",
                        type: "button",
                        disabled: actionDisabled,
                        onClick: () => recordRelease(record),
                      }, savingId === record.id ? "Recording..." : "Record handover")
                      )
                      : h("small", { className: "muted" }, "This assistance record is not ready for handover.")
            );
          }))
          : h("div", { className: "program-roster-no-record" },
            badge("Eligible · no assistance record"),
            h("small", { className: "muted" }, "This resident matches the program benefit, but has no assistance record to release.")
          ),
      ]),
      empty: eligibleResidents.length
        ? "No eligible residents match your search and roster status filter."
        : "No active residents match this program's benefit category.",
    })
    ),
    h("section", { className: "program-release-history" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h3", null, "Program transaction history"),
          h("p", { className: "section-note" }, "Only transactions recorded against this schedule appear here.")
        ),
        h("span", { className: "badge" }, `${programTransactions.length} transactions`)
      ),
      h(Table, {
        headers: ["Transaction", "Released", "Beneficiary", "Benefit", "Amount / items", "Recorded by"],
        rows: programTransactions.map((transaction) => [
          `REL-${transaction.id}`,
          displayDate(transaction.distributedAt),
          transaction.beneficiary,
          transaction.benefit,
          transaction.amount,
          transaction.distributedBy,
        ]),
        empty: "No releases have been recorded for this program yet.",
      })
    ),
    distributions.some((distribution) => !distribution.programId) && h("section", { className: "program-release-history" },
      h("div", { className: "section-title" },
        h("div", null,
          h("h3", null, "Earlier releases without a schedule link"),
          h("p", { className: "section-note" }, "These historical transactions predate program-linked release tracking and remain unassigned.")
        )
      ),
      h(Table, {
        headers: ["Released", "Beneficiary", "Benefit", "Amount / items", "Recorded by"],
        rows: distributions
          .filter((distribution) => !distribution.programId)
          .map((transaction) => [
            displayDate(transaction.distributedAt),
            transaction.beneficiary,
            transaction.benefit,
            transaction.amount,
            transaction.distributedBy,
          ]),
        empty: "No unlinked historical transactions.",
      })
    )
  );
}

//#endregion
//#region Program scheduling
function Programs({ actions, editable = true }) {
  const programs = actions.data.programs;
  const [selectedProgramId, setSelectedProgramId] = useState(String(programs[0]?.id || ""));
  const programRosterRef = useRef(null);
  const addSchedule = () => actions.setModal("program");
  const editSchedule = (program) => actions.setModal({ type: "programEdit", record: program });
  const canMonitorTransactions = ["barangay", "distribution_staff"].includes(actions.session.role);
  const isDistributionStaff = actions.session.role === "distribution_staff";
  const isSecretary = actions.session.role === "secretary";
  const activeBeneficiaryNames = new Set((actions.data.beneficiaries || [])
    .filter((beneficiary) => String(beneficiary.status || "").toLowerCase() === "active")
    .map((beneficiary) => String(beneficiary.name || "").trim().toLowerCase()));
  const pendingAssignments = (actions.data.assistance || []).filter((record) =>
    record.status === "Pending"
    && activeBeneficiaryNames.has(String(record.beneficiary || "").trim().toLowerCase())
  );
  const todayKey = localDateKey();
  const dueTodayCount = pendingAssignments.filter((record) => String(record.date || "").slice(0, 10) === todayKey).length;
  const overdueCount = pendingAssignments.filter((record) => {
    const date = String(record.date || "").slice(0, 10);
    return date && date < todayKey;
  }).length;
  const openProgramCount = programs.filter((program) =>
    ["scheduled", "open"].includes(String(program.status || "").trim().toLowerCase())
  ).length;
  const selectSchedule = (program) => {
    actions.setQuery("");
    setSelectedProgramId(String(program.id));
    window.requestAnimationFrame(() => {
      const roster = programRosterRef.current;
      if (!roster) return;
      roster.scrollIntoView({ behavior: "smooth", block: "start" });
      roster.focus({ preventScroll: true });
    });
  };
  const savedProgramSection = programs.length
    ? h(React.Fragment, null,
      h("section", { className: "saved-program-schedules card" },
        h("div", { className: "section-title" },
          h("div", null,
            h("h2", null, isDistributionStaff ? "Select a program schedule" : "Saved Program Schedules"),
            h("p", { className: "section-note" }, isDistributionStaff
              ? "Choose a schedule to view its eligible resident roster and record handovers."
              : "Schedules created by the Secretary appear here and are shared with the distribution team.")
          ),
          h("span", { className: "badge" }, `${programs.length} ${programs.length === 1 ? "schedule" : "schedules"}`)
        ),
        isDistributionStaff
          ? h("div", { className: "distribution-schedule-list" }, programs.map((program) => {
            const benefit = String(program.benefit || "").trim();
            const includesAllResidents = benefit.toLowerCase() === "all residents";
            const eligibleCount = (actions.data.beneficiaries || []).filter((beneficiary) =>
              String(beneficiary.status || "").toLowerCase() === "active"
              && (includesAllResidents || categoryList(beneficiary.category).some((category) =>
                category.toLowerCase() === benefit.toLowerCase()
              ))
            ).length;
            const isSelected = String(program.id) === selectedProgramId;

            return h("article", {
              className: `distribution-schedule-card ${isSelected ? "is-selected" : ""}`,
              key: program.id,
            },
              h("div", { className: "distribution-schedule-card-top" },
                h("span", { className: "distribution-schedule-id" }, program.id),
                badge(program.status || "Scheduled")
              ),
              h("h3", null, program.name),
              h("div", { className: "distribution-schedule-benefit" },
                h(Icon, { id: "users" }),
                h("span", null, benefit || "No benefit selected")
              ),
              h("div", { className: "distribution-schedule-meta" },
                h("span", null, h("b", null, "Date and time"), h("small", null, scheduleDetails(program))),
                h("span", null, h("b", null, "Venue"), h("small", null, program.venue || "Not specified")),
                h("span", null, h("b", null, "Amount / Items"), h("small", null, program.releaseAmount || "Not specified")),
                h("span", null, h("b", null, "Eligible residents"), h("small", null, `${eligibleCount} active ${eligibleCount === 1 ? "resident" : "residents"}`)),
                h("span", null, h("b", null, "Assigned staff"), h("small", null, program.assignedStaff || "Not assigned"))
              ),
              h("div", { className: "distribution-schedule-instructions" },
                h("b", null, "Distribution instructions"),
                h("p", null, program.instructions || "No special instructions provided.")
              ),
              h("button", {
                className: isSelected ? "primary distribution-schedule-action" : "secondary distribution-schedule-action",
                type: "button",
                onClick: () => selectSchedule(program),
                "aria-pressed": isSelected,
                "aria-label": `Show residents included in ${program.name}, benefit ${benefit || "not selected"}`,
              }, h(Icon, { id: "users" }), isSelected ? "View resident roster" : "Select and view residents")
            );
          }))
          : h(Table, {
            headers: ["Program ID", "Program", "Benefit", "Date", "Time", "Venue", "Amount / Items", "Assigned Staff", "Instructions", "Status", ...(editable ? ["Action"] : [])],
            rows: programs.map((program) => {
              const cells = [program.id, program.name, program.benefit || "No audience selected", program.date, program.time || "-", program.venue, program.releaseAmount || "-", program.assignedStaff || "-", program.instructions || "-", badge(program.status)];
              if (editable) {
                cells.push(h("div", { className: "row-actions" }, h("button", { className: "small-action", type: "button", onClick: () => editSchedule(program), "aria-label": `Edit ${program.name}` }, "Edit"), h("button", { className: "small-action muted", type: "button", onClick: () => updateProgramStatus(actions, program) }, program.status === "Completed" ? "Reopen" : "Complete"), h("button", { className: "small-action danger", type: "button", onClick: () => deleteProgram(actions, program) }, "Delete")));
              }
              return cells;
            }),
          })
      )
    )
    : h(
      "section",
      { className: "card program-empty" },
      h("h2", null, "No programs yet"),
      h("p", null, editable ? "Create the programs your barangay will run. Saved schedules will appear here." : "No program schedules have been posted yet.")
    );

  return h(
    "section",
    { className: "grid" },
    isDistributionStaff && h(DistributionPageIntro, {
      icon: "calendar",
      section: "Scheduling and distribution",
      title: "Scheduling and distribution",
      description: "Review resident release dates alongside program events and record completed handovers.",
      detail: "Resident and program release schedules",
    }),
    isDistributionStaff && h("section", { className: "distribution-workload", "aria-label": "Distribution workload summary" },
      h("div", { className: "distribution-workload-heading" },
        h("div", null,
          h("span", { className: "distribution-workload-eyebrow" }, "TODAY'S OPERATIONS"),
          h("h2", null, "Distribution at a glance"),
          h("p", null, `Assignments and open events · ${new Date().toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric" })}`)
        ),
        h("span", { className: "distribution-workload-live" }, h("span", { "aria-hidden": "true" }), "Live schedule")
      ),
      h("div", { className: "distribution-workload-grid" },
        [
          { label: "Due today", value: dueTodayCount, detail: "Resident assignments scheduled for today", tone: "today" },
          { label: "Past due", value: overdueCount, detail: "Assignments to follow up with residents", tone: "overdue" },
          { label: "Open program events", value: openProgramCount, detail: "Scheduled or open program rosters", tone: "programs" },
        ].map((item) => h("article", { className: `distribution-workload-card ${item.tone}`, key: item.label },
          h("span", null, item.label),
          h("b", null, item.value),
          h("small", null, item.detail)
        ))
      )
    ),
    editable && h("div", { className: "toolbar" }, h("button", { className: "primary", type: "button", onClick: addSchedule }, h(Icon, { id: "calendar" }), " Add Program Schedule")),
    isSecretary && h("section", { className: "distribution-workload secretary-schedule-overview", "aria-label": "Schedule overview" },
      h("div", { className: "distribution-workload-heading" },
        h("div", null,
          h("span", { className: "distribution-workload-eyebrow" }, "SECRETARY · PLANNING DESK"),
          h("h2", null, "Schedule overview"),
          h("p", null, "A quick view of resident releases and upcoming program activity.")
        ),
        h("span", { className: "distribution-workload-live" }, h("span", { "aria-hidden": "true" }), "Shared with distribution staff")
      ),
      h("div", { className: "distribution-workload-grid" },
        [
          { label: "Residents awaiting release", value: pendingAssignments.length, detail: "Active resident assignments not yet handed over", tone: "today" },
          { label: "Releases today", value: dueTodayCount, detail: "Pending assignments scheduled for today", tone: "today" },
          { label: "Past due releases", value: overdueCount, detail: "Assignments that may need a new date", tone: "overdue" },
          { label: "Open program schedules", value: openProgramCount, detail: "Scheduled or open community events", tone: "programs" },
        ].map((item) => h("article", { className: `distribution-workload-card ${item.tone}`, key: item.label },
          h("span", null, item.label),
          h("b", null, item.value),
          h("small", null, item.detail)
        ))
      )
    ),
    (editable || isDistributionStaff) && h(AssistanceReleaseSchedule, {
      actions,
      mode: editable ? "secretary" : "distribution",
    }),
    isDistributionStaff && savedProgramSection,
    canMonitorTransactions && programs.length > 0 && (isDistributionStaff
      ? h(ProgramReleaseTransactions, { actions, selectedProgramId, rosterRef: programRosterRef })
      : h(ProgramBeneficiaryMonitor, { actions })),
    !isDistributionStaff && savedProgramSection
  );
}

function ProgramBeneficiaryMonitor({ actions }) {
  const programs = actions.data.programs;
  const [selectedProgramId, setSelectedProgramId] = useState(String(programs[0]?.id || ""));
  const program = programs.find((item) => String(item.id) === selectedProgramId) || programs[0];
  if (!program) return null;

  const benefit = String(program.benefit || "").trim();
  const isAllResidentsProgram = benefit.toLowerCase() === "all residents";
  const eligibleResidents = actions.data.beneficiaries.filter((beneficiary) =>
    String(beneficiary.status || "").toLowerCase() === "active"
    && (isAllResidentsProgram
      || categoryList(beneficiary.category).some((category) => category.toLowerCase() === benefit.toLowerCase()))
  );
  const assistance = actions.data.assistance || [];
  const distributions = actions.data.distributions || [];
  const programTransactions = distributions.filter((distribution) => String(distribution.programId || "") === String(program.id));
  const distributionsByAssistance = new Map(distributions
    .filter((distribution) => distribution.assistanceId)
    .map((distribution) => [String(distribution.assistanceId), distribution]));
  const residentTransactions = new Map();
  programTransactions.forEach((transaction) => {
    const key = String(transaction.beneficiary || "").trim().toLowerCase();
    const transactions = residentTransactions.get(key) || [];
    transactions.push(transaction);
    residentTransactions.set(key, transactions);
  });
  const residentAssistance = new Map();
  assistance.forEach((record) => {
    const key = String(record.beneficiary || "").trim().toLowerCase();
    const records = residentAssistance.get(key) || [];
    records.push(record);
    residentAssistance.set(key, records);
  });
  const releaseStatusByResident = new Map(eligibleResidents.map((beneficiary) => {
    const key = String(beneficiary.name || "").trim().toLowerCase();
    const records = residentAssistance.get(key) || [];
    const transactions = residentTransactions.get(key) || [];
    const availableRecords = records.filter((record) =>
      ["Pending", "Completed"].includes(record.status) && !distributionsByAssistance.has(String(record.id))
    );
    const unlinkedClaims = records.filter((record) =>
      record.status === "Claimed" && !distributionsByAssistance.has(String(record.id))
    );
    const claimsUnderOtherPrograms = records.filter((record) => {
      const distribution = distributionsByAssistance.get(String(record.id));
      return record.status === "Claimed"
        && distribution
        && String(distribution.programId || "") !== String(program.id);
    });
    const latestTransaction = transactions
      .slice()
      .sort((first, second) => String(second.distributedAt || "").localeCompare(String(first.distributedAt || "")))[0];
    let status = "No assistance assigned";
    if (transactions.length) {
      status = "Released";
    } else if (availableRecords.length) {
      status = "Ready to release";
    } else if (unlinkedClaims.length) {
      status = "Previously released · unlinked";
    } else if (claimsUnderOtherPrograms.length) {
      status = "Released under another program";
    }

    return [key, { status, latestTransaction, availableRecords, unlinkedClaims, claimsUnderOtherPrograms }];
  }));
  const readyResidents = [...releaseStatusByResident.values()].filter((item) => item.status === "Ready to release").length;
  const releasedResidents = [...releaseStatusByResident.values()].filter((item) => item.status === "Released").length;
  const slots = Number(program.slots || 0);
  const search = actions.query.trim().toLowerCase();
  const rows = eligibleResidents
    .filter((beneficiary) => `${beneficiary.name} ${beneficiary.purok} ${beneficiary.category}`.toLowerCase().includes(search))
    .map((beneficiary) => {
      const releaseStatus = releaseStatusByResident.get(String(beneficiary.name || "").trim().toLowerCase());
      const lastActivity = releaseStatus.latestTransaction
        ? `Released ${displayDate(releaseStatus.latestTransaction.distributedAt)}`
        : releaseStatus.availableRecords.length
          ? `${releaseStatus.availableRecords.length} assistance record(s) ready`
          : releaseStatus.unlinkedClaims.length
            ? "Previous handover has no schedule link"
            : releaseStatus.claimsUnderOtherPrograms.length
              ? "Claim recorded under a different program"
              : "No assistance record has been assigned";

      return [
        beneficiary.name,
        beneficiary.purok || "-",
        beneficiary.category || benefit,
        badge(beneficiary.status || "Unknown"),
        badge(releaseStatus.status),
        lastActivity,
      ];
    });

  return h("section", { className: "card program-monitor" },
    h("div", { className: "section-title program-monitor-heading" },
      h("div", null,
        h("h2", null, "Program Beneficiary & Release Monitor"),
        h("p", { className: "section-note" }, "Eligible active profiles are matched by benefit category. Release progress comes from transactions saved against this schedule.")
      ),
      h("label", { className: "field program-monitor-select" },
        h("span", null, "Select program"),
        h("select", { className: "select", value: String(program.id), onChange: (event) => setSelectedProgramId(event.target.value) },
          programs.map((item) => h("option", { key: item.id, value: String(item.id) }, `${item.name} · ${item.benefit || "No audience selected"}`))
        )
      )
    ),
    h("div", { className: "program-monitor-summary" },
      h("div", { className: "program-monitor-detail" },
        h("b", null, program.name),
        h("span", null, `${benefit} · ${scheduleDetails(program)}`),
        h("span", null, `${eligibleResidents.length} matching profiles · ${slots > 0 ? `${slots} program slots` : "No slot limit"}`)
      ),
      h("div", { className: "program-monitor-stages" },
        [["Eligible", eligibleResidents.length], ["Ready to release", readyResidents], ["Released", releasedResidents]].map(([label, count]) =>
          h("div", { className: "program-monitor-stage", key: label }, h("b", null, count), h("span", null, label))
        )
      ),
      h("p", { className: "program-monitor-capacity" },
        slots > 0
          ? `${Math.max(0, slots - releasedResidents)} of ${slots} program slots remaining`
          : "This program has no slot limit."
      )
    ),
    h("div", { className: "toolbar program-monitor-toolbar" },
      h(SearchBar, { query: actions.query, setQuery: actions.setQuery, placeholder: "Search eligible beneficiaries" })
    ),
    h(Table, {
      headers: ["Beneficiary", "Purok", "Program Category", "Profile Status", "Release Status", "Last Activity"],
      rows,
      empty: "No beneficiary profiles match this program category.",
    })
  );
}

async function updateProgramStatus(actions, program) {
  const status = program.status === "Completed" ? "Open" : "Completed";
  const programs = actions.data.programs.map((item) => item.id === program.id ? { ...item, status } : item);
  const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Updated program ${program.name} to ${status}`, { ...actions.data, programs }));
  if (saved) actions.notify("Program status updated.");
}

async function deleteProgram(actions, program) {
  if (!window.confirm(`Delete schedule ${program.name}?`)) return;
  const programs = actions.data.programs.filter((item) => item.id !== program.id);
  const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Deleted schedule ${program.name}`, { ...actions.data, programs }));
  if (saved) actions.notify("Program schedule deleted.");
}

//#endregion
//#region Resident and staff messaging
function messageThreads(messages) {
  const threads = new Map();
  (messages || []).forEach((message) => {
    const key = String(message.userId);
    const current = threads.get(key) || [];
    current.push(message);
    threads.set(key, current);
  });
  return [...threads.values()].sort((a, b) => String(b[b.length - 1].time).localeCompare(String(a[a.length - 1].time)));
}

function waitingResidentThreads(messages) {
  return messageThreads(messages).filter((thread) => thread[thread.length - 1].from === "resident");
}

function messageSender(message) {
  if (message.from === "resident") return `${message.name || "Resident"} (${message.username || "resident"})`;
  const staff = message.staffName || "Staff";
  return message.staffUsername ? `${staff} (${message.staffUsername})` : staff;
}

function ResidentMessageDesk({ actions }) {
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const thread = actions.data.messages || [];
  const threadRef = useRef(null);
  const messageInputRef = useRef(null);

  useEffect(() => {
    if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight;
  }, [thread.length]);

  async function send(event) {
    event.preventDefault();
    const body = text.trim();
    if (!body || sending) return;
    setSending(true);
    try {
      await window.BarangayCareService.sendStaffMessage({ kind: "Message", body });
      await actions.refreshData();
      setText("");
      actions.notify("Your message has been sent to barangay staff.");
    } catch (error) {
      actions.notify(error.message);
    } finally {
      setSending(false);
    }
  }

  const suggestions = [
    { title: "My assistance", detail: "Ask about benefits or release details.", prompt: "Can you tell me about my assistance?", icon: "aid" },
    { title: "Upcoming programs", detail: "Find out what programs are coming up.", prompt: "What programs are coming up?", icon: "calendar" },
    { title: "Account support", detail: "Get help with your resident account.", prompt: "I need help with my account.", icon: "users" },
  ];
  const hasConversation = thread.length > 0;
  const lastMessage = thread[thread.length - 1];
  const conversationStatus = !lastMessage
    ? "Staff can reply here"
    : lastMessage.from === "resident"
      ? "Waiting for staff reply"
      : "Staff replied";
  const trimmedTextLength = text.trim().length;

  return h("form", { className: "resident-message-card", onSubmit: send },
    h("header", { className: "resident-message-header" },
      h("div", { className: "resident-message-heading" },
        h("span", { className: "resident-message-icon", "aria-hidden": "true" }, h(Icon, { id: "message" })),
        h("div", null,
          h("h2", null, "Message the Barangay Team"),
          h("p", null, "Your messages and staff replies stay in this conversation.")
        )
      ),
      h("span", { className: `resident-message-status${lastMessage?.from === "resident" ? " is-waiting" : ""}` },
        h("span", { "aria-hidden": "true" }),
        conversationStatus,
        hasConversation && h("small", null, `${thread.length} ${thread.length === 1 ? "message" : "messages"}`)
      )
    ),
    h("div", {
      className: "resident-message-thread",
      ref: threadRef,
      role: "log",
      "aria-label": "Conversation with barangay staff",
      "aria-live": "polite",
    }, thread.length
      ? thread.map((message) => h("div", { className: `chat-message ${message.from === "resident" ? "user" : "ai"}`, key: message.id },
        h("span", { className: "message-meta" }, `${message.from === "resident" ? "You" : messageSender(message)} · ${message.time}`),
        h("div", { className: "resident-message-body" }, message.body)
      ))
      : h("div", { className: "resident-message-empty" },
        h("span", { className: "resident-message-empty-icon", "aria-hidden": "true" }, h(Icon, { id: "message" })),
        h("span", { className: "resident-message-empty-eyebrow" }, "HERE TO HELP"),
        h("h3", null, "How can we help you today?"),
        h("p", null, "Send a private message to barangay staff. Choose a topic below or write your own question."),
        h("div", { className: "resident-message-suggestions-wrap" },
          h("span", { className: "resident-message-suggestions-label" }, "COMMON TOPICS"),
          h("div", { className: "resident-message-suggestions", "aria-label": "Suggested message topics" }, suggestions.map((suggestion) =>
            h("button", {
              className: "resident-message-suggestion",
              type: "button",
              key: suggestion.title,
              onClick: () => {
                setText(suggestion.prompt);
                messageInputRef.current?.focus();
              },
            },
            h("span", { className: "resident-message-suggestion-icon", "aria-hidden": "true" }, h(Icon, { id: suggestion.icon })),
            h("span", { className: "resident-message-suggestion-copy" },
              h("strong", null, suggestion.title),
              h("small", null, suggestion.detail)
            ),
            h("span", { className: "resident-message-suggestion-arrow", "aria-hidden": "true" }, "→")
            )
          ))
        )
      )
    ),
    h("div", { className: "resident-message-compose" },
      h("div", { className: "resident-message-compose-heading" },
        h("span", null, "Your message"),
        h("small", null, "Barangay staff will reply in this conversation.")
      ),
      h("label", { className: "field resident-message-field" },
        h("textarea", {
          className: "input",
          ref: messageInputRef,
          rows: 3,
          value: text,
          maxLength: 1000,
          placeholder: "Share your question or concern with barangay staff…",
          "aria-label": "Write your message to barangay staff",
          onChange: (event) => setText(event.target.value),
          onKeyDown: (event) => {
            if (event.key !== "Enter" || event.shiftKey) return;
            event.preventDefault();
            if (text.trim() && !sending) event.currentTarget.form.requestSubmit();
          },
        })
      ),
      h("div", { className: "resident-message-compose-footer" },
        h("span", { className: "resident-message-hint" }, hasConversation ? "Press Enter to send · Shift + Enter for a new line" : "Please do not include passwords or other sensitive account information."),
        h("div", { className: "resident-message-send-row" },
          h("span", { className: `resident-message-counter ${trimmedTextLength > 900 ? "near-limit" : ""}`, "aria-live": "polite" }, `${text.length}/1000`),
          h("button", { className: "primary", type: "submit", disabled: sending || !trimmedTextLength }, h(Icon, { id: "message" }), sending ? "Sending..." : "Send message")
        )
      )
    )
  );
}

function residentNotice(actions, resident) {
  const place = placeName(actions.data.settings);
  const name = resident.name || resident.username;
  const linked = String(resident.beneficiaryName || resident.name || "").trim().toLowerCase();
  const beneficiary = actions.data.beneficiaries.find((item) => String(item.name || "").trim().toLowerCase() === linked);
  const assistance = actions.data.assistance.filter((item) => String(item.beneficiary || "").trim().toLowerCase() === linked);
  const benefits = categoryList(beneficiary?.category);
  assistance.forEach((item) => {
    const type = String(item.type || "").trim();
    if (type && !benefits.some((benefit) => benefit.toLowerCase() === type.toLowerCase())) benefits.push(type);
  });
  const lines = [`Good day, ${name}. This is ${place}.`];
  lines.push(benefits.length ? `Your resident account includes ${benefits.join(" and ")}.` : "Your resident account is active, and no assistance is listed on it yet.");
  assistance.forEach((item) => {
    const detail = [`${item.type} is ${String(item.status || "pending").toLowerCase()}`];
    if (item.date) detail.push(`as of ${item.date}`);
    if (item.amount && item.amount !== "As released") detail.push(`(${item.amount})`);
    lines.push(`${detail.join(" ")}.`);
  });
  const program = (actions.data.programs || []).find((item) => {
    if (item.status === "Completed") return false;
    const benefit = String(item.benefit || "").trim().toLowerCase();
    if (!benefit) return false;
    if (benefit === "all residents") return true;
    return benefits.some((type) => type.toLowerCase() === benefit);
  });
  if (program) lines.push(`${program.name} is set for ${scheduleDetails(program)}${program.assignedStaff ? `, with ${program.assignedStaff} assigned` : ""}.`);
  if (program?.instructions) lines.push(`Instructions: ${program.instructions}`);
  lines.push("Please bring a valid ID when you claim the assistance. You can reply here if you need to clarify the release or report a concern.");
  return lines.join(" ").slice(0, 1000);
}

function ResidentNoticeComposer({ actions, onSent }) {
  const residents = actions.data.users.filter(isRegisteredResident);
  const [userId, setUserId] = useState(residents[0] ? String(residents[0].id) : "");
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const resident = residents.find((item) => String(item.id) === userId) || residents[0] || null;

  useEffect(() => {
    if (!userId && residents[0]) setUserId(String(residents[0].id));
  }, [userId, residents]);

  useEffect(() => {
    if (resident) setBody(residentNotice(actions, resident));
  }, [userId]);

  async function send(event) {
    event.preventDefault();
    const message = body.trim();
    if (!resident || !message || sending) return;
    setSending(true);
    try {
      await window.BarangayCareService.sendStaffMessage({ userId: Number(resident.id), body: message });
      await actions.refreshData();
      if (onSent) onSent(String(resident.id));
      actions.notify(`Message sent to ${resident.name}. It is now on that resident account.`);
    } catch (error) {
      actions.notify(error.message);
    } finally {
      setSending(false);
    }
  }

  if (!residents.length) {
    return h("div", { className: "card" }, h("h2", null, "AI Message"), h("p", null, "Add a resident account first. Messages can only be sent to a registered resident."));
  }

  const thread = (actions.data.messages || []).filter((message) => String(message.userId) === String(resident.id) || String(message.username || "").toLowerCase() === String(resident.username || "").toLowerCase());
  const residentReplied = thread.length > 0 && thread[thread.length - 1].from === "resident";

  return h("form", { className: "card", onSubmit: send },
    h("div", { className: "section-title" }, h("h2", null, "AI Message"), h("span", { className: `badge ${residentReplied ? "warn" : "ok"}` }, residentReplied ? "Reply from resident" : "Resident account")),
    h("p", { className: "stat-note" }, `Messages from ${resident.name}'s resident account (${resident.username}) appear in this staff account conversation.`),
    h("div", { className: "chat-box message-thread" }, thread.length
      ? thread.map((message) => h("div", { className: `chat-message ${message.from === "resident" ? "user" : "ai"}`, key: message.id },
        h("span", { className: "message-meta" }, message.from === "resident"
          ? `${messageSender(message)} · reply to this staff account · ${message.time}`
          : `${messageSender(message)} · ${message.time}`),
        message.body
      ))
      : h("div", { className: "empty compact" }, `No messages with ${resident.name} yet.`)),
    h("label", { className: "field" }, h("span", null, "Resident account"), h("select", { className: "select", value: String(resident.id), onChange: (event) => setUserId(event.target.value) }, residents.map((item) => h("option", { key: item.id, value: String(item.id) }, `${item.name} (${item.username})`)))),
    h("label", { className: "field" }, h("span", null, "Message"), h("textarea", { className: "input", rows: 8, value: body, maxLength: 1000, onChange: (event) => setBody(event.target.value) })),
    h("button", { className: "primary", type: "submit", disabled: sending || !body.trim() }, sending ? "Sending..." : `Send to ${resident.name}`)
  );
}

function AIChatbot({ actions, staffMode }) {
  const nextProgram = useMemo(() => {
    const sorted = [...actions.data.programs].sort((a, b) => new Date(a.date) - new Date(b.date));
    return sorted.find((program) => program.status !== "Completed" && new Date(program.date) >= startOfToday()) || sorted[0];
  }, [actions.data.programs]);
  const defaultQuestion = nextProgram ? `When is the release schedule for ${nextProgram.name}?` : "Is there an assistance release schedule?";
  const [input, setInput] = useState(defaultQuestion);
  const [messages, setMessages] = useState([
    {
      from: "ai",
      text: staffMode
        ? "Choose the resident account you want to reach. The draft uses that resident's name and the assistance listed on their account, and sending it puts the message on their page."
        : "Hello. I can help you check the assistance listed on your account. Messages from the barangay staff also appear in the panel beside this chat.",
    },
  ]);
  const [waiting, setWaiting] = useState(false);

  async function askBot(event) {
    event.preventDefault();
    const question = input.trim();
    if (!question || waiting) return;
    setMessages((current) => [...current, { from: "user", text: question }]);
    setInput("");
    setWaiting(true);
    try {
      const reply = await window.BarangayCareService.chat(
        question,
        actions.session.role,
        actions.session.beneficiaryName || actions.session.name
      );
      setMessages((current) => [...current, { from: "ai", text: reply }]);
    } catch (error) {
      actions.notify(`Laravel chatbot request failed: ${error.message}`);
      setMessages((current) => [...current, { from: "ai", text: "I could not contact Laravel to answer that. Please try again." }]);
    } finally {
      setWaiting(false);
    }
  }

  if (staffMode) {
    return h("section", { className: "ai-message-center" }, h(ResidentNoticeComposer, { actions }));
  }

  return h("section", { className: "ai-message-center" }, h(ResidentMessageDesk, { actions }));
}

//#endregion
//#region Reports, account administration, and system monitoring
function Table({ headers, rows, empty = "No records found.", className = "", rowAttributes }) {
  return h("div", { className: `table-wrap ${className}`.trim() }, h("table", null, h("thead", null, h("tr", null, headers.map((head) => h("th", { key: head }, head)))), h("tbody", null, rows.length ? rows.map((row, index) => h("tr", { key: index, ...(rowAttributes?.(row, index) || {}) }, row.map((cell, cellIndex) => h("td", { key: cellIndex, "data-label": headers[cellIndex] }, cell)))) : h("tr", null, h("td", { className: "empty", colSpan: headers.length }, empty)))));
}

function badge(value) {
  return h("span", { className: `badge ${statusClass(value)}` }, value);
}

function RecentAssistance({ data }) {
  return h("section", { className: "table-wrap" }, h("div", { className: "section-title card" }, h("h2", null, "Recent Assistance Records"), h("span", { className: "badge ok" }, "Updated")), h(Table, { headers: ["Record", "Beneficiary", "Type", "Amount", "Date", "Status", "Staff"], rows: data.assistance.map((a) => [a.id, a.beneficiary, a.type, a.amount, a.date, badge(a.status), a.staff]) }));
}

function scheduleDetails(program) {
  return [program.date, program.time, program.venue].filter(Boolean).join(" · ") || "Details to be announced";
}

function Chart({ data, title = "Beneficiary Distribution" }) {
  const groups = beneficiaryCategories;
  const counts = groups.map((group) => data.beneficiaries.filter((b) => hasCategory(b.category, group)).length);
  const max = Math.max(...counts, 1);
  return h("section", { className: "chart-panel monitoring-category-chart" }, h("div", { className: "section-title" }, h("h2", null, title), h("span", { className: "badge ok" }, "On file")), h("div", { className: "bars" }, groups.map((group, index) => h("div", { className: "bar", key: group }, h("span", null, group), h("div", { className: "bar-track" }, h("div", { className: "bar-fill", style: { width: `${(counts[index] / max) * 100}%` } })), h("b", null, counts[index])))));
}

function isRegisteredResident(user) {
  const role = String(user?.role || "").trim().toLowerCase();
  return role === "user" || role === "resident" || role === "resident account";
}

function Users({ actions }) {
  const beneficiaryType = (user) => actions.data.beneficiaries.find((beneficiary) => beneficiary.name === user.beneficiaryName)?.category || "-";
  const isCaptain = actions.session.isBarangayCaptain === true;
  const isAdmin = actions.session.role === "admin";
  const isDistributionStaff = actions.session.role === "distribution_staff";
  const canCreateResidentAccount = actions.session.role === "secretary";
  const canCreateStaffAccount = isCaptain || isAdmin;
  const canEditResidentAccounts = ["secretary", "assistance_officer", "distribution_staff"].includes(actions.session.role);
  const canChangeStaffAccountStatus = (role) => isCaptain && ["Secretary", "Assistance Officer", "Distribution Staff"].includes(role);
  const canResetStaffAccountPassword = canChangeStaffAccountStatus;
  const users = isCaptain
    ? actions.data.users.filter((user) => !["Admin", "Barangay Captain"].includes(accountRoleLabel(user.role)))
    : canEditResidentAccounts
      ? actions.data.users.filter(isRegisteredResident)
      : actions.data.users;
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("All roles");
  const [accountScope, setAccountScope] = useState("all");
  const normalizedSearch = search.trim().toLowerCase();
  const residentCount = users.filter(isRegisteredResident).length;
  const scopedUsers = users.filter((user) => accountScope === "all"
    || (accountScope === "residents" ? isRegisteredResident(user) : !isRegisteredResident(user)));
  const accounts = scopedUsers.map((user) => ({
    user,
    role: accountRoleLabel(user.role),
    beneficiaryType: isRegisteredResident(user) ? beneficiaryType(user) : "-",
  }));
  const roleOptions = ["All roles", ...new Set(accounts.map((account) => account.role).sort())];
  const filteredAccounts = accounts.filter((account) => {
    const matchesRole = roleFilter === "All roles" || account.role === roleFilter;
    const searchableText = [
      account.user.name,
      account.user.username,
      account.user.email,
      account.user.beneficiaryName,
      account.role,
      account.beneficiaryType,
    ].join(" ").toLowerCase();
    return matchesRole && searchableText.includes(normalizedSearch);
  });
  const activeCount = users.filter((user) => String(user.status || "").toLowerCase() === "active").length;
  const accountScopeLabel = accountScope === "residents" ? " resident" : accountScope === "staff" ? " staff" : "";
  const headers = isCaptain
    ? ["Account holder", "Account type", "Access status", "Account action"]
    : ["Name", "Role", "Beneficiary Type", "Username", "Email", "Status", "Actions"];
  async function changeStaffAccountStatus(user) {
    const status = String(user.status || "").toLowerCase() === "active" ? "Inactive" : "Active";
    const action = status === "Inactive" ? "deactivate" : "reactivate";
    if (!window.confirm(`Are you sure you want to ${action} ${user.name}'s ${accountRoleLabel(user.role)} account?`)) {
      return;
    }

    try {
      await window.BarangayCareService.updateStaffAccountStatus(user.id, status);
      await actions.refreshData();
      actions.notify(`${user.name}'s account is now ${status.toLowerCase()}.`);
    } catch (error) {
      actions.notify(`Could not update staff account: ${error.message}`);
    }
  }
  async function deleteInactiveStaffAccount(user) {
    const role = accountRoleLabel(user.role);
    if (!window.confirm(`Permanently delete ${user.name}'s inactive ${role} account? They will lose account access. Historical assistance and audit records will be kept.`)) {
      return;
    }

    try {
      await window.BarangayCareService.deleteStaffAccount(user.id);
      await actions.refreshData();
      actions.notify(`${user.name}'s inactive account was deleted. Historical records were kept.`);
    } catch (error) {
      actions.notify(`Could not delete staff account: ${error.message}`);
    }
  }
  function resetStaffAccountPassword(user) {
    actions.setModal({ type: "staffPasswordReset", record: user });
  }
  const rows = filteredAccounts.map(({ user, role, beneficiaryType: type }) => [
    isCaptain
      ? h("div", { className: "captain-account-holder" },
        h("span", { className: "captain-account-mark", "aria-hidden": "true" }, String(user.name || "?").trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join("").toUpperCase()),
        h("span", { className: "captain-account-name" }, h("strong", null, user.name || "-"), h("small", null, "Account record"))
      )
      : user.name || "-",
    isCaptain ? h("span", { className: "captain-account-role" }, role) : role,
    ...(isCaptain ? [] : [
      type,
      user.username || "-",
      user.email || "-",
      badge(user.status || "Unknown"),
      canEditResidentAccounts && isRegisteredResident(user)
        ? h("button", { className: "secondary small-action", type: "button", onClick: () => actions.setModal({ type: "userEdit", record: user }) }, "Edit")
        : "-",
    ]),
    ...(isCaptain ? [
      badge(user.status || "Unknown"),
      canChangeStaffAccountStatus(role)
        ? h("div", { className: "captain-account-actions" },
          canResetStaffAccountPassword(role) && h("button", {
            className: "secondary small-action",
            type: "button",
            onClick: () => resetStaffAccountPassword(user),
            "aria-label": `Reset password for ${role} account belonging to ${user.name}`,
          }, "Reset password"),
          h("button", {
            className: "secondary small-action",
            type: "button",
            onClick: () => changeStaffAccountStatus(user),
            "aria-label": `${String(user.status || "").toLowerCase() === "active" ? "Deactivate" : "Activate"} ${role} account for ${user.name}`,
          }, String(user.status || "").toLowerCase() === "active" ? "Deactivate" : "Reactivate"),
          String(user.status || "").toLowerCase() === "inactive"
            ? h("button", {
              className: "small-action danger",
              type: "button",
              onClick: () => deleteInactiveStaffAccount(user),
              "aria-label": `Delete inactive ${role} account for ${user.name}`,
            }, "Delete")
            : h("span", { className: "captain-delete-hint" }, "Deactivate to delete")
        )
        : "Monitoring only",
    ] : []),
  ]);
  return h("section", { className: "grid registered-accounts-page" },
    isDistributionStaff && h(DistributionPageIntro, {
      icon: "users",
      section: "Resident accounts",
      title: "Resident accounts",
      description: "Find registered residents and update account details when needed. Staff access remains limited to authorized account tasks.",
      detail: "Resident directory",
    }),
    !isDistributionStaff && h("section", { className: "registered-accounts-heading" },
      h("div", null,
        h("span", { className: "registered-accounts-eyebrow" }, isCaptain ? "ACCOUNT LEDGER" : "ACCOUNT DIRECTORY"),
        h("h2", null, isCaptain ? "Account ledger" : "Account directory"),
        h("p", null, isCaptain
          ? "Monitor account status, create staff sign-ins, reset passwords, and review staffing coverage. Resident service and contact details are not available in this view."
          : "Review and update resident account access and contact details.")
      ),
      (canCreateResidentAccount || canCreateStaffAccount) && h("div", { className: "registered-accounts-actions" },
        canCreateResidentAccount && h("button", { className: "primary", onClick: () => actions.setModal("user") }, h(Icon, { id: "users" }), " Add Resident Account"),
        canCreateStaffAccount && h("button", { className: "primary", onClick: () => actions.setModal("staff") }, h(Icon, { id: "users" }), isAdmin ? " Add Barangay Captain Account" : " Add Staff Account")
      )
    ),
    h("section", { className: `registered-accounts-summary ${isCaptain ? "captain-accounts-summary" : ""} ${isDistributionStaff ? "distribution-staff-account-summary" : ""}`, "aria-label": "Account summary" },
      h(MiniCard, { value: isCaptain ? (actions.data.monitoring?.accounts ?? users.length) : residentCount, label: isCaptain ? "Total accounts" : "Resident accounts" }),
      h(MiniCard, { value: activeCount, label: isCaptain ? "Active accounts" : "Active residents" }),
      h(MiniCard, { value: isCaptain ? (actions.data.monitoring?.residentAccounts ?? residentCount) : users.length - activeCount, label: isCaptain ? "Resident accounts" : "Inactive residents" }),
      isCaptain && h(MiniCard, { value: actions.data.monitoring?.staffAccounts ?? users.length - residentCount, label: "Office accounts" }),
      isCaptain && h(MiniCard, { value: users.length - activeCount, label: "Inactive accounts" })
    ),
    isCaptain && h("div", { className: "notice" }, "Resident entries show account name and access status only. Profile, contact, and assistance details remain restricted; staff access can be managed by the Captain."),
    isAdmin && h("div", { className: "notice" }, "Admin can create Barangay Captain accounts only. Resident accounts are created by the Barangay Secretary."),
    h("section", { className: "card registered-accounts-list" },
      h("div", { className: "section-title" },
        h("div", null, h("h2", null, isCaptain ? "Account records" : isDistributionStaff ? "Registered residents" : "Resident and staff accounts"), h("p", { className: "section-note" }, isCaptain ? "Review account access, reset staff passwords, and manage account status. Resident profile and contact details are not shown." : "Search residents by name, username, email, or benefit type.")),
        h("span", { className: "badge" }, `${filteredAccounts.length} ${filteredAccounts.length === 1 ? "account" : "accounts"}`)
      ),
      isCaptain && h("div", { className: "captain-ledger-note" },
        h("span", { className: "captain-ledger-icon", "aria-hidden": "true" }, h(Icon, { id: "report" })),
        h("span", null, h("strong", null, "Account register"), h("small", null, "One row per resident or staff account. Account changes remain traceable to the account record.")),
        h("span", { className: "captain-ledger-access" }, h(Icon, { id: "lock" }), " Captain access")
      ),
      isCaptain && h("div", { className: "captain-account-scopes", role: "group", "aria-label": "Filter accounts by type" },
        [
          ["all", "All accounts", users.length],
          ["residents", "Resident accounts", residentCount],
          ["staff", "Staff accounts", users.length - residentCount],
        ].map(([scope, label, count]) => h("button", {
          key: scope,
          className: `secondary captain-account-scope ${accountScope === scope ? "selected" : ""}`,
          type: "button",
          "aria-pressed": accountScope === scope,
          onClick: () => {
            setAccountScope(scope);
            setRoleFilter("All roles");
          },
        }, label, h("span", null, count)))
      ),
      h("div", { className: `registered-accounts-tools ${isDistributionStaff ? "distribution-staff-accounts-tools" : ""}` },
        h("label", { className: "search" },
          h(Icon, { id: "search" }),
          h("input", { className: "input", type: "search", placeholder: isDistributionStaff ? "Search residents by name, username, or email" : "Search registered accounts", "aria-label": "Search registered accounts", value: search, onChange: (event) => setSearch(event.target.value) })
        ),
        !isDistributionStaff && h(FilterSelect, { label: "Filter by role", value: roleFilter, onChange: setRoleFilter, options: roleOptions })
      ),
      h(Table, { headers, empty: scopedUsers.length ? "No accounts match your search and role filter." : "No accounts in this group yet.", rows, className: isCaptain ? "captain-account-ledger" : "" }),
      h("p", { className: "registered-accounts-count", "aria-live": "polite" }, `Showing ${filteredAccounts.length} of ${scopedUsers.length}${accountScopeLabel} accounts`)
    )
  );
}

function Modules({ actions }) {
  useEffect(() => {
    actions.refreshData().catch(() => {});
  }, []);

  const data = actions.data;
  const activeBeneficiaries = data.beneficiaries.filter((item) => item.status === "Active").length;
  const pendingBeneficiaries = data.beneficiaries.filter((item) => (item.verificationStatus || "Pending") === "Pending").length;
  const pendingAid = data.assistance.filter((item) => item.status === "Pending").length;
  const claimedAid = data.assistance.filter((item) => item.status === "Claimed").length;
  const ahead = data.programs.filter((item) => new Date(item.date) >= startOfToday()).length;
  const modules = [
    ["Beneficiary Management", "Resident profiles currently stored for this barangay.", data.beneficiaries.length ? `${data.beneficiaries.length} profiles · ${activeBeneficiaries} active, ${pendingBeneficiaries} pending verification` : "No profiles yet", data.beneficiaries.length > 0],
    ["Assistance Monitoring", "Support records currently stored, including pending and claimed.", data.assistance.length ? `${data.assistance.length} records · ${pendingAid} pending, ${claimedAid} claimed` : "No assistance yet", data.assistance.length > 0],
    ["Scheduling", "Program dates currently saved by the office.", data.programs.length ? (ahead ? `${ahead} still ahead of today` : `${data.programs.length} saved · none still ahead of today`) : "No programs yet", data.programs.length > 0],
    ["Reports", "Printable summary of the assistance records in the database.", data.assistance.length ? `${data.assistance.length} records ready to print` : "Nothing to print yet", data.assistance.length > 0],
    ["Audit Logs", "Latest changes stored for this barangay.", data.logs.length ? `${data.logs.length} entries` : "No entries yet", data.logs.length > 0, "Audit Logs"],
    ["Backup and Restore", "Download or replace the beneficiaries, programs, assistance, and accounts now in the database.", data.beneficiaries.length || data.programs.length || data.assistance.length ? `${data.beneficiaries.length} profiles, ${data.programs.length} programs, ${data.assistance.length} assistance` : "No records to back up yet", true, "Backup and Restore"],
  ];

  return h(
    "section",
    { className: "grid" },
    h("div", { className: "notice" }, "These cards use the records currently stored in the database. Open Audit Logs or Backup and Restore to use them."),
    h("div", { className: "module-grid" }, modules.map(([name, detail, status, active, page]) => h(
      "article",
      { className: "card module-card", key: name },
      h("div", { className: "section-title" }, h("h2", null, name), h("span", { className: `badge ${active ? "ok" : "warn"}` }, active ? "In use" : "Empty")),
      h("p", { className: "stat-note" }, detail),
      h("b", { className: "module-status" }, status),
      page && h("button", { className: "secondary", type: "button", onClick: () => actions.navigate(page) }, "Open")
    )))
  );
}

function Logs({ logs, title = "Audit Logs", empty = "No activity to show." }) {
  return h("section", { className: "card" },
    h("div", { className: "section-title" }, h("h2", null, title), h("span", { className: "badge" }, `${logs.length} Entries`)),
    logs.length
      ? h("div", { className: "list" }, logs.map((log, index) => h("div", { className: "list-item", key: `${log.time}-${index}` }, h("b", null, log.actor), h("span", null, log.action), h("span", null, log.time))))
      : h("div", { className: "empty compact" }, empty)
  );
}

function Backup({ actions }) {
  if (actions.session.role === "admin") {
    return h("section", { className: "card" },
      h("h2", null, "Backup and Restore"),
      h("div", { className: "notice" }, "Backup exports and restoration are restricted. Admin accounts have monitoring-only access.")
    );
  }

  async function download() {
    try {
      const text = await window.BarangayCareService.backup();
      const blob = new Blob([text], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "barangaycare-backup.json";
      link.click();
      URL.revokeObjectURL(url);
      actions.notify("Backup downloaded from Laravel.");
    } catch (error) {
      actions.notify(`Could not create a Laravel backup: ${error.message}`);
    }
  }

  async function restore(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (!window.confirm("Restore this backup and replace the current records?")) return;
    try {
      const data = JSON.parse(await file.text());
      const result = await window.BarangayCareService.restore(data);
      await actions.refreshData();
      actions.notify(result.message || "Backup restored.");
    } catch (error) {
      actions.notify(`Could not restore the backup: ${error.message}`);
    }
  }

  return h("section", { className: "card" }, h("div", { className: "section-title" }, h("h2", null, "Backup and Restore"), h("div", { className: "row-actions" }, h("button", { className: "primary", onClick: download }, h(Icon, { id: "report" }), " Download Backup"), h("label", { className: "secondary" }, "Restore Backup", h("input", { className: "profile-file", type: "file", accept: "application/json", onChange: restore })))), h("p", { className: "stat-note" }, "Restore replaces beneficiaries, programs, assistance, applications, distribution records, and accounts. Passwords already stored in Laravel are kept when the file does not include them."), h("textarea", { className: "input", rows: 12, readOnly: true, value: JSON.stringify(actions.data, null, 2) }));
}

function Monitoring({ actions, includeAuditLogs = false }) {
  const staffAndResidentLogs = includeAuditLogs
    ? actions.data.logs
    : actions.data.logs.filter((log) => log.actorRole === "barangay" || log.actorRole === "user").slice(0, 8);
  const pendingReleases = actions.data.assistance
    .filter((record) => record.status === "Pending")
    .slice()
    .sort((first, second) => String(first.date || "").localeCompare(String(second.date || "")));
  const todayLabel = startOfToday().toLocaleDateString("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  });

  return h("div", { className: `grid barangay-monitoring ${includeAuditLogs ? "admin-audit-monitoring" : ""}` },
    h("section", { className: "monitoring-hero" },
      h("div", null,
        h("span", { className: "monitoring-eyebrow" }, includeAuditLogs ? "BARANGAYCARE · ADMIN MONITORING" : "BARANGAYCARE · OFFICE MONITORING"),
        h("h2", null, includeAuditLogs ? "Activity monitor & audit logs" : "Barangay operations at a glance"),
        h("p", null, includeAuditLogs
          ? "Review what is happening across barangay services and trace recorded actions in one monitoring workspace."
          : "A clear overview of resident profiles, assistance releases, program schedules, and recent office activity.")
      ),
      h("div", { className: `monitoring-hero-aside ${includeAuditLogs ? "admin-monitoring-hero-aside" : ""}` },
        includeAuditLogs && h("div", { className: "monitoring-audit-total" },
          h("span", { className: "monitoring-audit-total-mark", "aria-hidden": "true" }, h(Icon, { id: "report" })),
          h("div", null, h("b", null, actions.data.logs.length), h("span", null, actions.data.logs.length === 1 ? "Audit entry" : "Audit entries"))
        ),
        h("div", { className: "monitoring-date" },
          h("span", { className: "monitoring-date-label" }, "TODAY"),
          h("b", null, todayLabel)
        ),
        includeAuditLogs && h("button", {
          className: "primary monitoring-audit-jump",
          type: "button",
          onClick: () => document.getElementById("monitoring-audit-log")?.scrollIntoView({ behavior: "smooth", block: "start" }),
        }, h(Icon, { id: "report" }), " View audit trail")
      )
    ),
    h("section", { className: "monitoring-indicators" },
      h("div", { className: "monitoring-section-heading" },
        h("div", null,
          h("h2", null, includeAuditLogs ? "Operations snapshot" : "Service indicators"),
          h("p", null, includeAuditLogs ? "Current service totals from barangay records" : "Current totals from barangay records")
        ),
        h("span", { className: "badge ok" }, includeAuditLogs ? "Live overview" : "Records overview")
      ),
      h(Stats, { data: actions.data })
    ),
    h(MonitoringActivity, { logs: staffAndResidentLogs, includeAuditLogs, id: "monitoring-audit-log" }),
    h("section", { className: "content-grid monitoring-content-grid" },
      h(WorkQueue, { actions, pending: pendingReleases, title: includeAuditLogs ? "Pending assistance" : "For release" }),
      h(Chart, { data: actions.data, title: includeAuditLogs ? "Beneficiary categories" : "Beneficiary Distribution" })
    )
  );
}

function MonitoringActivity({ logs, includeAuditLogs = false, id }) {
  const [query, setQuery] = useState("");
  const [actorRole, setActorRole] = useState("All");
  const visibleLogs = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    return logs.filter((log) => {
      const matchesRole = actorRole === "All" || accountRoleLabel(log.actorRole || "") === actorRole;
      const matchesQuery = !normalizedQuery || JSON.stringify(log).toLowerCase().includes(normalizedQuery);
      return matchesRole && matchesQuery;
    });
  }, [logs, query, actorRole]);
  const actorRoles = [...new Set(logs.map((log) => accountRoleLabel(log.actorRole || "")).filter(Boolean))].sort((first, second) => first.localeCompare(second));

  return h("section", { className: `card monitoring-activity ${includeAuditLogs ? "monitoring-audit-panel" : ""}`, id },
    h("div", { className: "section-title" },
      h("div", null,
        h("h2", null, includeAuditLogs ? "Recent activity and audit trail" : "Staff and Resident Activity"),
        h("p", { className: "section-note" }, includeAuditLogs
          ? "A chronological record of staff, resident, and system actions."
          : "Recent account and service events recorded by the system.")
      ),
      h("span", { className: "badge" }, `${logs.length} ${logs.length === 1 ? "entry" : "entries"}`)
    ),
    includeAuditLogs && h("div", { className: "toolbar monitoring-audit-toolbar" },
      h(SearchBar, { query, setQuery, placeholder: "Search actor, action, role, or date" }),
      h(FilterSelect, { label: "Actor role", value: actorRole, onChange: setActorRole, options: ["All", ...actorRoles] }),
      (query.trim() || actorRole !== "All") && h("button", {
        className: "secondary",
        type: "button",
        onClick: () => {
          setQuery("");
          setActorRole("All");
        },
      }, "Clear filters"),
      h("span", { className: "badge monitoring-audit-count" }, `Showing ${visibleLogs.length} of ${logs.length}`)
    ),
    visibleLogs.length
      ? h("div", { className: "monitoring-activity-list" }, visibleLogs.map((log, index) => h("article", {
          className: "monitoring-activity-item",
          key: `${log.time}-${index}`,
        },
        h("span", { className: "monitoring-activity-mark", "aria-hidden": "true" }, h(Icon, { id: log.actorRole === "user" ? "users" : "dashboard" })),
        h("div", { className: "monitoring-activity-copy" },
          h("div", { className: "monitoring-activity-meta" },
            h("b", null, log.actor || "BarangayCare"),
            h("span", null, accountRoleLabel(log.actorRole || "")),
            h("time", null, log.time || "Time unavailable")
          ),
          h("p", null, log.action || "Activity recorded")
        )
      )))
      : h("div", { className: "empty compact" }, logs.length
        ? "No audit entries match your search and role filters."
        : includeAuditLogs
          ? "No activity or audit entries have been recorded yet."
          : "No staff or resident activity has been recorded yet.")
  );
}

function Reports({ actions }) {
  const reportData = actions.session.role === "user" ? scopedData(actions) : actions.data;
  const isResident = actions.session.role === "user";
  const isAdmin = actions.session.role === "admin";
  const secretaryReport = actions.session.role === "secretary";
  const canViewClaimedAmounts = ["user", "secretary", "distribution_staff"].includes(actions.session.role);
  const canReportDistributions = ["secretary", "admin", "assistance_officer", "distribution_staff", "auditor"].includes(actions.session.role);
  const [filters, setFilters] = useState({ startDate: "", endDate: "", program: "All", status: "All", search: "" });
  const [appliedFilters, setAppliedFilters] = useState({ startDate: "", endDate: "", program: "All", status: "All", search: "" });
  const [generatedAt, setGeneratedAt] = useState(new Date().toLocaleString("en-PH", { hour12: false }));
  const assistanceRecords = reportData.assistance.map((record) => ({
    ...record,
    amount: canViewClaimedAmounts ? record.amount : "Confidential",
  }));
  const distributions = (reportData.distributions || []).map((record) => ({
    ...record,
    amount: canViewClaimedAmounts ? record.amount : "Confidential",
  }));
  const programOptions = [...new Set([
    ...reportData.assistance.map((record) => record.type),
    ...distributions.map((record) => record.benefit),
    ...reportData.programs.flatMap((program) => [program.name, program.benefit]),
  ].filter(Boolean))].sort((first, second) => first.localeCompare(second));
  const matchesFilters = (record, date, label, status) => {
    const recordDate = String(date || "").slice(0, 10);
    if (appliedFilters.startDate && (!recordDate || recordDate < appliedFilters.startDate)) return false;
    if (appliedFilters.endDate && (!recordDate || recordDate > appliedFilters.endDate)) return false;
    const labels = Array.isArray(label) ? label : [label];
    if (appliedFilters.program !== "All" && !labels.some((value) => String(value || "").trim().toLowerCase() === appliedFilters.program.toLowerCase())) return false;
    if (appliedFilters.status !== "All" && status !== appliedFilters.status) return false;
    return !appliedFilters.search || JSON.stringify(record).toLowerCase().includes(appliedFilters.search.toLowerCase());
  };
  const assistance = assistanceRecords.filter((record) => matchesFilters(record, record.date, record.type, record.status));
  const filteredDistributions = distributions.filter((record) => matchesFilters(record, record.distributedAt, record.benefit, "Distributed"));
  const filteredPrograms = (reportData.programs || []).filter((program) => matchesFilters(program, program.date, [program.name, program.benefit], program.status));
  const statusRecords = assistance;
  const statusStatuses = [...new Set(statusRecords.map((record) => record.status).filter(Boolean))]
    .sort((first, second) => first.localeCompare(second));
  const statusSummary = statusStatuses.map((status) => ({
    status,
    count: statusRecords.filter((record) => record.status === status).length,
  }));
  const benefitRecords = canReportDistributions
    ? filteredDistributions.map((record) => ({ benefit: record.benefit }))
    : assistance.map((record) => ({ benefit: record.type }));
  const benefitSummary = [...new Set(benefitRecords.map((record) => record.benefit).filter(Boolean))]
    .map((benefit) => ({
      benefit,
      count: benefitRecords.filter((record) => record.benefit === benefit).length,
    }))
    .sort((first, second) => second.count - first.count || first.benefit.localeCompare(second.benefit));
  const maxStatusCount = Math.max(...statusSummary.map((item) => item.count), 1);
  const maxBenefitCount = Math.max(...benefitSummary.map((item) => item.count), 1);
  const activeBeneficiaries = reportData.beneficiaries.filter((beneficiary) => String(beneficiary.status || "").toLowerCase() === "active").length;
  const reportScopeNote = secretaryReport
    ? "Secretary report coverage: beneficiary profiles, assistance records, distribution handovers, and schedules available to your account. Audit logs are restricted and are not included."
    : isAdmin
      ? "Admin monitoring includes all beneficiary, assistance, distribution, and schedule records across the system. Claimed amounts are confidential and visible only to the resident, Barangay Secretary, and Distribution Staff."
      : "Beneficiary totals show the current profile snapshot and are not changed by the report filters.";

  function generateReport(event) {
    event.preventDefault();
    if (filters.startDate && filters.endDate && filters.startDate > filters.endDate) {
      actions.notify("The start date must be on or before the end date.");
      return;
    }
    setAppliedFilters({ ...filters, search: filters.search.trim() });
    setGeneratedAt(new Date().toLocaleString("en-PH", { hour12: false }));
  }

  function resetReport() {
    const emptyFilters = { startDate: "", endDate: "", program: "All", status: "All", search: "" };
    setFilters(emptyFilters);
    setAppliedFilters(emptyFilters);
    setGeneratedAt(new Date().toLocaleString("en-PH", { hour12: false }));
  }

  function downloadCsv() {
    const rows = [
      ["Record Type", "Reference", "Beneficiary", "Program / Benefit", "Amount", "Status", "Date", "Responsible Staff"],
      ...filteredDistributions.map((record) => ["Distribution", `DIST-${record.id}`, record.beneficiary, record.benefit, record.amount, "Distributed", record.distributedAt, record.distributedBy]),
      ...assistance.map((record) => ["Assistance", record.id, record.beneficiary, record.type, record.amount, record.status, record.date, record.staff]),
      ...filteredPrograms.map((program) => ["Schedule", `PROGRAM-${program.id}`, "", program.name, "", program.status, `${program.date || ""} ${program.time || ""}`.trim(), program.assignedStaff || ""]),
    ];
    const csv = rows.map((row) => row.map((value) => {
      const text = String(value ?? "");
      const safeText = /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
      return `"${safeText.replaceAll('"', '""')}"`;
    }).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = `barangaycare-report-${localDateKey()}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  const filterToolbar = !isResident && h("form", { className: "toolbar report-filters", onSubmit: generateReport },
    h("div", { className: "report-filter-heading" },
      h("div", null, h("h2", null, "Build a report"), h("p", null, "Choose a date range and records to include.")),
      h("span", { className: "report-filter-hint" }, "Filters apply when you generate the report")
    ),
    h("label", { className: "filter-select" }, h("span", null, "Start date"), h("input", { className: "input", type: "date", value: filters.startDate, onChange: (event) => setFilters((current) => ({ ...current, startDate: event.target.value })) })),
    h("label", { className: "filter-select" }, h("span", null, "End date"), h("input", { className: "input", type: "date", value: filters.endDate, onChange: (event) => setFilters((current) => ({ ...current, endDate: event.target.value })) })),
    h(FilterSelect, { label: "Program / benefit", value: filters.program, onChange: (program) => setFilters((current) => ({ ...current, program })), options: ["All", ...programOptions] }),
    h(FilterSelect, { label: "Status", value: filters.status, onChange: (status) => setFilters((current) => ({ ...current, status })), options: ["All", "Pending", "Distributed", "Claimed", "Completed", "Scheduled", "Open", "Ongoing", "Cancelled"] }),
    h("label", { className: "filter-select report-search" }, h("span", null, "Search records"), h("input", { className: "input", type: "search", placeholder: "Beneficiary, reference, or staff", value: filters.search, onChange: (event) => setFilters((current) => ({ ...current, search: event.target.value })) })),
    h("div", { className: "report-filter-actions" },
      h("button", { className: "primary", type: "submit" }, h(Icon, { id: "report" }), " Generate report"),
      h("button", { className: "secondary", type: "button", onClick: resetReport }, "Reset filters"),
      h("span", { className: "report-filter-action-spacer" }),
      h("button", { className: "secondary", type: "button", onClick: downloadCsv }, "Export CSV")
    )
  );
  const appliedFilterSummary = [
    appliedFilters.startDate && `From ${appliedFilters.startDate}`,
    appliedFilters.endDate && `Through ${appliedFilters.endDate}`,
    appliedFilters.program !== "All" && `Program / benefit: ${appliedFilters.program}`,
    appliedFilters.status !== "All" && `Status: ${appliedFilters.status}`,
    appliedFilters.search && `Search: ${appliedFilters.search}`,
  ].filter(Boolean);

  return h("section", { className: "grid report-page" },
    h("section", { className: "report-hero no-print" },
      h("div", null,
        h("span", { className: "report-eyebrow" }, h(Icon, { id: "report" }), " BARANGAYCARE · REPORTING"),
        h("h2", null, isResident ? "My assistance report" : secretaryReport ? "Secretary report for the Barangay Captain" : "Community service overview"),
        h("p", null, isResident ? "A clear record of your assistance and distribution history." : secretaryReport ? "A clear, printable summary of resident profiles, assistance records, distribution handovers, and schedules available to the Secretary." : "A current, printable view of beneficiary services, assistance, distributions, and schedules.")
      ),
      h("div", { className: "report-generated-badge" },
        h("span", { className: "report-live-indicator", "aria-hidden": "true" }),
        h("div", null, h("b", null, "Report generated"), h("span", null, generatedAt))
      )
    ),
    filterToolbar,
    h("section", { className: "print-sheet report-sheet" },
      h("div", { className: "print-head report-print-head" },
        h("div", null,
          h("span", { className: "report-print-kicker" }, "BARANGAYCARE · OFFICIAL REPORT"),
          h("h2", null, isResident ? "Personal Assistance Report" : "Barangay Service Report"),
          h("p", null, placeName(actions.data.settings))
        ),
        h("div", { className: "report-print-meta" },
          h("b", null, generatedAt),
          h("span", null, `${roles[actions.session.role].name} · ${isResident ? "Personal records" : secretaryReport ? "Prepared for the Barangay Captain" : "Administrative report"}`)
        )
      ),
      isResident && h("div", { className: "notice no-print" }, "This report contains only your linked assistance records."),
      !isResident && h("div", { className: "report-scope-note", role: "note" }, reportScopeNote),
      !isResident && h("div", { className: "report-applied-summary" },
        h("span", { className: "report-filter-summary" }, `Generated ${generatedAt}${appliedFilterSummary.length ? ` · ${appliedFilterSummary.join(" · ")}` : " · All records"}`),
        h("span", { className: "badge ok" }, `${filteredDistributions.length + assistance.length + filteredPrograms.length} matching operational records`)
      ),
      h("section", { className: "stats report-stats", "aria-label": "Report summary" },
        !isResident && h(Stat, { title: "Registered beneficiaries", value: reportData.beneficiaries.length, note: "Current profiles; report filters do not apply", className: "report-stat report-stat-people" }),
        secretaryReport && h(Stat, { title: "Active beneficiaries", value: activeBeneficiaries, note: "Current profile snapshot", className: "report-stat report-stat-people" }),
        h(Stat, { title: isResident ? "My assistance records" : "Assistance assignments", value: assistance.length, note: isResident ? "Linked to your account" : "Within selected filters", className: "report-stat report-stat-assistance" }),
        canReportDistributions && h(Stat, { title: "Distribution records", value: filteredDistributions.length, note: "Recorded handovers", className: "report-stat report-stat-distributions" }),
        !isResident && h(Stat, { title: "Program schedules", value: filteredPrograms.length, note: "Within selected filters", className: "report-stat report-stat-schedules" })
      ),
      !isResident && h("section", { className: "report-insights", "aria-label": "Report analytics" },
        h("article", { className: "report-insight-card" },
          h("div", { className: "report-insight-heading" },
            h("div", null, h("h3", null, "Assistance status"), h("p", null, "Assistance assignments grouped by their current status.")),
            h("span", { className: "badge" }, `${statusRecords.length} total`)
          ),
          statusSummary.length
            ? h("div", { className: "report-bar-list" }, statusSummary.map(({ status, count }) => h("div", { className: "report-bar-row", key: status },
                h("span", { className: "report-bar-label" }, status),
                h("div", { className: "report-bar-track", role: "progressbar", "aria-label": `${status} records`, "aria-valuemin": 0, "aria-valuemax": maxStatusCount, "aria-valuenow": count }, h("div", { className: `report-bar-fill ${statusClass(status)}`, style: { width: `${(count / maxStatusCount) * 100}%` } })),
                h("b", null, count)
              )))
            : h("div", { className: "empty compact report-empty" }, "Assistance status details will appear when assignments are recorded.")
        ),
        h("article", { className: "report-insight-card" },
          h("div", { className: "report-insight-heading" },
            h("div", null, h("h3", null, canReportDistributions ? "Distributions by benefit" : "Assistance assignments by benefit"), h("p", null, canReportDistributions ? "Recorded assistance handovers by benefit type." : "Recorded assistance assignments grouped by benefit type.")),
            h("span", { className: "badge" }, `${benefitRecords.length} total`)
          ),
          benefitSummary.length
            ? h("div", { className: "report-bar-list" }, benefitSummary.slice(0, 6).map(({ benefit, count }) => h("div", { className: "report-bar-row", key: benefit },
                h("span", { className: "report-bar-label", title: benefit }, benefit),
                h("div", { className: "report-bar-track", role: "progressbar", "aria-label": `${benefit} records`, "aria-valuemin": 0, "aria-valuemax": maxBenefitCount, "aria-valuenow": count }, h("div", { className: "report-bar-fill ok", style: { width: `${(count / maxBenefitCount) * 100}%` } })),
                h("b", null, count)
              )))
            : h("div", { className: "empty compact report-empty" }, canReportDistributions ? "Distribution totals will appear after a handover is recorded." : "Assistance totals will appear when assignments are recorded.")
        )
      ),
      h(ReportSection, {
        title: "Assistance records",
        description: "Recorded assistance activity and claim details.",
        count: assistance.length,
        headers: ["Record", "Beneficiary", "Type", "Amount", "Date", "Status", "Claimed Date", "Claimed By", "Staff"],
        rows: assistance.map((record) => [record.id, record.beneficiary, record.type, record.amount, record.date, badge(record.status), record.claimedAt || "-", record.claimedBy || "-", record.staff]),
        empty: "No assistance records match the selected filters.",
      }),
      !isResident && h(React.Fragment, null,
        canReportDistributions && h(ReportSection, {
          title: secretaryReport ? "Distribution handover evidence" : "Distribution history",
          description: "Completed handovers recorded by the barangay.",
          count: filteredDistributions.length,
          headers: ["Reference", "Beneficiary", "Benefit", "Amount", "Distributed By", "Date"],
          rows: filteredDistributions.map((record) => [`DIST-${record.id}`, record.beneficiary, record.benefit, record.amount, record.distributedBy, displayDate(record.distributedAt)]),
          empty: "No distributions match the selected filters.",
        }),
        h(ReportSection, {
          title: "Schedules and programs",
          description: "Program dates, venues, assigned staff, and status.",
          count: filteredPrograms.length,
          headers: ["Program", "Benefit", "Date", "Time", "Venue", "Assigned Staff", "Status"],
          rows: filteredPrograms.map((program) => [program.name, program.benefit || "No audience selected", program.date || "-", program.time || "-", program.venue || "-", program.assignedStaff || "-", badge(program.status || "Scheduled")]),
          empty: "No schedules match the selected filters.",
        })
      ),
      h("p", { className: "report-footer" }, "Prepared through BarangayCare digital beneficiary management and social assistance monitoring system.")
    )
  );
}

function ReportSection({ title, description, count, headers, rows, empty }) {
  return h("section", { className: "report-data-section" },
    h("div", { className: "report-data-heading" },
      h("div", null, h("h3", null, title), h("p", null, description)),
      h("span", { className: "badge" }, `${count} ${count === 1 ? "record" : "records"}`)
    ),
    h(Table, { headers, rows, empty })
  );
}

//#endregion
//#region Profiles and settings
function Profile({ actions }) {
  if (actions.session.role === "user") return h(ResidentProfile, { actions });
  const isCaptain = actions.session.isBarangayCaptain === true;
  const mustChangePassword = actions.session.mustChangePassword === true;
  const roleName = isCaptain ? "Barangay Captain" : roles[actions.session.role].name;
  const account = actions.data.users.find((user) => user.username === actions.session.username) || {
    name: actions.session.name || "Resident Account",
    role: roleName,
    username: actions.session.username,
    status: "Active",
  };
  const [profile, setProfile] = useState({
    name: account.name || "",
    contact: account.contact || "",
    address: account.address || "Barangay Taft, Surigao City",
    email: account.email || "",
    emergencyContact: account.emergencyContact || "",
    profileImage: account.profileImage || "",
  });
  const [security, setSecurity] = useState({ currentPassword: "", newPassword: "", confirmPassword: "" });
  const profileInitials = profile.name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase() || "BC";
  const photoInputId = `profile-photo-${account.username}`;

  function updateProfile(field, value) {
    setProfile((current) => ({ ...current, [field]: value }));
  }

  function updateProfileImage(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      actions.notify("Please choose an image file for the profile picture.");
      return;
    }
    if (file.size > 1500000) {
      actions.notify("Profile picture must be 1.5 MB or smaller.");
      return;
    }
    const reader = new FileReader();
    reader.onload = () => updateProfile("profileImage", reader.result);
    reader.readAsDataURL(file);
    event.target.value = "";
  }

  function updateSecurity(field, value) {
    setSecurity((current) => ({ ...current, [field]: value }));
  }

  async function saveProfile(event) {
    event.preventDefault();
    try {
      const result = await window.BarangayCareService.updateProfile(profile);
      await actions.refreshData();
      const nextSession = persistSession({ ...actions.session, ...result.user, profileImage: profile.profileImage });
      actions.setSession(nextSession);
      actions.notify("Profile information saved.");
    } catch (error) {
      actions.notify(`Could not save profile: ${error.message}`);
    }
  }

  async function changePassword(event) {
    event.preventDefault();
    if (security.newPassword.length < 8) {
      actions.notify("New password must be at least 8 characters.");
      return;
    }
    if (security.newPassword !== security.confirmPassword) {
      actions.notify("New password and confirmation do not match.");
      return;
    }
    try {
      await window.BarangayCareService.changePassword(
        account.username,
        security.currentPassword,
        security.newPassword,
        security.confirmPassword
      );
      setSecurity({ currentPassword: "", newPassword: "", confirmPassword: "" });
      if (mustChangePassword) {
        actions.setSession(persistSession({ ...actions.session, mustChangePassword: false }));
        try {
          await actions.refreshData();
        } catch (error) {
          actions.notify(`Password updated. Your workspace data could not be refreshed: ${error.message}`);
          return;
        }
        actions.notify("Temporary password changed. You can now use the staff workspace.");
      } else {
        actions.notify("Password updated. Use the new password on next login.");
      }
    } catch (error) {
      actions.notify(`Could not update password: ${error.message}`);
    }
  }

  return h(
    "section",
    { className: "profile-layout" },
    h(ProfileHero, {
      eyebrow: `${roleName.toUpperCase()} ACCOUNT`,
      title: isCaptain ? "Captain profile and security" : `${roleName} profile and security`,
      description: mustChangePassword
        ? "Your Captain provided a temporary password. Replace it now to unlock your staff workspace."
        : isCaptain
        ? "Keep your official contact details current and protect access to your Barangay Captain account."
        : "Keep your contact details current and protect your account with a strong password.",
      detail: mustChangePassword ? "A new password is required" : "Your profile changes apply only to your account",
    }),
    mustChangePassword && h("div", { className: "notice security-notice", role: "status" }, "For your protection, other pages are temporarily locked until you change the password provided by the Captain."),
    h(
      "div",
      { className: "profile-summary card" },
      h("div", { className: `profile-avatar ${profile.profileImage ? "has-photo" : ""}` },
        profile.profileImage ? h("img", { src: profile.profileImage, alt: `${profile.name || account.username} profile` }) : profileInitials
      ),
      h("div", { className: "photo-tools" },
        h("input", { id: photoInputId, className: "profile-file", type: "file", accept: "image/*", onChange: updateProfileImage }),
        h("label", { className: "secondary profile-upload", htmlFor: photoInputId }, h(Icon, { id: "users" }), " Upload Photo"),
        profile.profileImage && h("button", { className: "ghost", type: "button", onClick: () => updateProfile("profileImage", "") }, "Remove")
      ),
      h("div", { className: "profile-heading" },
        h("h2", null, profile.name || account.username),
        h("p", null, `@${account.username}`)
      ),
      h("div", { className: "profile-badges" }, h("span", { className: "badge ok" }, account.status || "Active"), h("span", { className: "badge" }, account.role || roles[actions.session.role].name)),
      h("div", { className: "profile-completion" },
        h("b", null, [profile.name, profile.contact, profile.email, profile.emergencyContact, profile.address, profile.profileImage].filter(Boolean).length),
        h("span", null, "of 6 profile details filled")
      ),
      h("div", { className: "profile-meta" },
        profileInfo("Location", profile.address || "Barangay Taft, Surigao City"),
        profileInfo("Contact", profile.contact || "Add a contact number"),
        profileInfo("Email", profile.email || "Add an email address"),
        profileInfo("Emergency", profile.emergencyContact || "Add emergency contact")
      )
    ),
    !mustChangePassword && h("form",
      { className: "form-panel profile-form-card", onSubmit: saveProfile },
      h("div", { className: "section-title profile-panel-head" }, h("div", null, h("h2", null, isCaptain ? "Official contact details" : "Personal Information"), h("p", { className: "section-note" }, isCaptain ? "Information residents and staff can use to contact your office." : "Keep your contact details updated so the barangay can reach you quickly.")), h("span", { className: "badge ok" }, "Editable")),
      h("div", { className: "form-grid" },
        controlledInput("Full Name", profile.name, (value) => updateProfile("name", value)),
        controlledInput("Contact Number", profile.contact, (value) => updateProfile("contact", value), "tel"),
        controlledInput("Email", profile.email, (value) => updateProfile("email", value), "email"),
        controlledInput("Emergency Contact", profile.emergencyContact, (value) => updateProfile("emergencyContact", value), "tel"),
        controlledInput("Address", profile.address, (value) => updateProfile("address", value), "text", "span-2"),
        h("div", { className: "profile-actions span-2" },
          h("p", null, "Changes are saved to this account only."),
          h("button", { className: "primary", type: "submit" }, h(Icon, { id: "settings" }), " Save Profile")
        )
      )
    ),
    h("form",
      { className: "form-panel profile-form-card security-card", onSubmit: changePassword },
      h("div", { className: "section-title profile-panel-head" }, h("div", null, h("h2", null, isCaptain ? "Sign-in security" : "Account Security"), h("p", { className: "section-note" }, "Use at least 8 characters for your new password.")), h("span", { className: "badge warn" }, "Password")),
      h("div", { className: "notice security-notice" }, h(Icon, { id: "lock" }), h("span", null, mustChangePassword ? "Enter the temporary password from the Captain, then set a new password you do not share with anyone." : "Changing your password updates the login credential for this account.")),
      h("div", { className: "form-grid" },
        controlledInput("Current Password", security.currentPassword, (value) => updateSecurity("currentPassword", value), "password"),
        controlledInput("New Password", security.newPassword, (value) => updateSecurity("newPassword", value), "password"),
        controlledInput("Confirm New Password", security.confirmPassword, (value) => updateSecurity("confirmPassword", value), "password"),
        h("div", { className: "profile-actions span-2" },
          h("p", null, "You will use the new password the next time you sign in."),
          h("button", { className: "primary", type: "submit" }, h(Icon, { id: "lock" }), " Update Password")
        )
      )
    )
  );
}

function ProfileHero({ eyebrow, title, description, detail }) {
  return h("section", { className: "profile-workspace-hero" },
    h("div", null,
      h("span", { className: "profile-workspace-eyebrow" }, h(Icon, { id: "settings" }), eyebrow),
      h("h2", null, title),
      h("p", null, description)
    ),
    h("div", { className: "profile-workspace-detail" },
      h(Icon, { id: "lock" }),
      h("span", null, detail)
    )
  );
}

function profileInfo(label, value) {
  return h("div", { className: "profile-info" }, h("span", null, label), h("b", null, value));
}

function ResidentProfile({ actions }) {
  const account = currentAccount(actions);
  const beneficiaryName = String(account.beneficiaryName || account.name || actions.session.name || "").trim().toLowerCase();
  const beneficiary = actions.data.beneficiaries.find((item) => String(item.name || "").trim().toLowerCase() === beneficiaryName);
  const benefits = categoryList(beneficiary?.category);
  const assistance = scopedAssistance(actions).filter((item) => !String(item.id || "").startsWith("selected-"));
  const pending = assistance.filter((item) => item.status === "Pending").length;
  const received = assistance.filter((item) => item.status === "Claimed" || item.status === "Completed").length;
  const [profile, setProfile] = useState({
    name: account.name || "",
    contact: account.contact || "",
    address: account.address || "",
    email: account.email || "",
    emergencyContact: account.emergencyContact || "",
    profileImage: account.profileImage || "",
  });
  const profileInitials = profile.name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase() || "R";
  const photoInputId = `resident-photo-${account.username || "account"}`;

  function updateProfile(field, value) {
    setProfile((current) => ({ ...current, [field]: value }));
  }

  function updateProfileImage(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      actions.notify("Please choose an image file for the profile picture.");
      return;
    }
    if (file.size > 1500000) {
      actions.notify("Profile picture must be 1.5 MB or smaller.");
      return;
    }
    const reader = new FileReader();
    reader.onload = () => updateProfile("profileImage", reader.result);
    reader.readAsDataURL(file);
    event.target.value = "";
  }

  async function saveProfile(event) {
    event.preventDefault();
    if (!profile.name.trim()) {
      actions.notify("Your full name is required.");
      return;
    }
    try {
      const result = await window.BarangayCareService.updateProfile({ ...profile, name: profile.name.trim() });
      await actions.refreshData();
      actions.setSession(persistSession({ ...actions.session, ...result.user, profileImage: profile.profileImage }));
      actions.notify("Your resident record was saved.");
    } catch (error) {
      actions.notify(`Could not save your resident record: ${error.message}`);
    }
  }

  return h("section", { className: "profile-layout" },
    h(ProfileHero, {
      eyebrow: "RESIDENT ACCOUNT",
      title: "Resident profile",
      description: "Keep your contact details current so the barangay can reach you about assistance and schedule updates.",
      detail: "Your benefits and purok are maintained by barangay staff",
    }),
    h("div", { className: "profile-summary card" },
      h("div", { className: `profile-avatar ${profile.profileImage ? "has-photo" : ""}` },
        profile.profileImage ? h("img", { src: profile.profileImage, alt: `${profile.name || account.username} profile` }) : profileInitials
      ),
      h("div", { className: "photo-tools" },
        h("input", { id: photoInputId, className: "profile-file", type: "file", accept: "image/*", onChange: updateProfileImage }),
        h("label", { className: "secondary profile-upload", htmlFor: photoInputId }, h(Icon, { id: "users" }), profile.profileImage ? "Change photo" : "Add profile photo"),
        profile.profileImage && h("button", { className: "ghost", type: "button", onClick: () => updateProfile("profileImage", "") }, "Remove photo")
      ),
      h("div", { className: "profile-heading" },
        h("h2", null, profile.name || account.username),
        h("p", null, `Resident account · ${account.username || actions.session.username}`)
      ),
      h("div", { className: "profile-badges" },
        h("span", { className: "badge ok" }, account.status || "Active"),
        h("span", { className: "badge" }, "Resident Account")
      ),
      h("div", { className: "profile-completion" },
        h("b", null, [profile.name, profile.contact, profile.email, profile.emergencyContact, profile.address, profile.profileImage].filter(Boolean).length),
        h("span", null, "of 6 profile details filled")
      ),
      h("div", { className: "profile-meta" },
        profileInfo("Benefits", benefits.length ? benefits.join(", ") : "No benefit is listed on this account yet"),
        profileInfo("Purok", beneficiary?.purok || "Not recorded yet"),
        profileInfo("Mobile number", profile.contact || "Add a mobile number"),
        profileInfo("Gmail", profile.email || "Add a Gmail address"),
        profileInfo("Emergency contact", profile.emergencyContact || "Add a person to call"),
        profileInfo("Home address", profile.address || "Add your home address"),
        profileInfo("Assistance", `${pending} pending, ${received} already received`)
      )
    ),
    h("form", { className: "form-panel profile-form-card", onSubmit: saveProfile },
      h("div", { className: "section-title profile-panel-head" },
        h("div", null, h("h2", null, "Contact information"), h("p", { className: "section-note" }, "Update the details the barangay uses to contact you. To change your password, open Settings."))
      ),
      h("div", { className: "form-grid" },
        controlledInput("Full Name", profile.name, (value) => updateProfile("name", value)),
        controlledInput("Mobile number", profile.contact, (value) => updateProfile("contact", value), "tel"),
        controlledInput("Gmail or email", profile.email, (value) => updateProfile("email", value), "email"),
        controlledInput("Emergency contact", profile.emergencyContact, (value) => updateProfile("emergencyContact", value), "tel"),
        controlledInput("Home address", profile.address, (value) => updateProfile("address", value), "text", "span-2"),
        h("div", { className: "profile-actions span-2" },
          h("p", null, "These details are saved on your resident account only."),
          h("button", { className: "primary", type: "submit" }, "Save profile changes")
        )
      )
    )
  );
}

function ResidentSettings({ actions }) {
  const office = { ...createDefaultState().settings, ...(actions.data.settings || {}) };
  const place = [office.barangayName, office.city].filter(Boolean).join(", ") || "the barangay hall";
  const [security, setSecurity] = useState({ currentPassword: "", newPassword: "", confirmPassword: "" });

  function updateSecurity(field, value) {
    setSecurity((current) => ({ ...current, [field]: value }));
  }

  async function changePassword(event) {
    event.preventDefault();
    if (security.newPassword.length < 8) {
      actions.notify("New password must be at least 8 characters.");
      return;
    }
    if (security.newPassword !== security.confirmPassword) {
      actions.notify("New password and confirmation do not match.");
      return;
    }
    try {
      await window.BarangayCareService.changePassword(
        actions.session.username,
        security.currentPassword,
        security.newPassword,
        security.confirmPassword
      );
      setSecurity({ currentPassword: "", newPassword: "", confirmPassword: "" });
      actions.notify("Password updated. Use the new password on your next login.");
    } catch (error) {
      actions.notify(`Could not update password: ${error.message}`);
    }
  }

  return h("section", { className: "grid resident-settings" },
    h("form", { className: "form-panel profile-form-card security-card", onSubmit: changePassword },
      h("div", { className: "section-title profile-panel-head" }, h("div", null, h("h2", null, "Account Security"), h("p", { className: "section-note" }, "Change your password to keep your resident account secure.")), h("span", { className: "badge warn" }, "Password")),
      h("div", { className: "notice security-notice" }, h(Icon, { id: "lock" }), h("span", null, "Use at least 8 characters. Your current password is required to make this change.")),
      h("div", { className: "form-grid" },
        controlledInput("Current Password", security.currentPassword, (value) => updateSecurity("currentPassword", value), "password"),
        controlledInput("New Password", security.newPassword, (value) => updateSecurity("newPassword", value), "password"),
        controlledInput("Confirm New Password", security.confirmPassword, (value) => updateSecurity("confirmPassword", value), "password"),
        h("div", { className: "profile-actions span-2" }, h("button", { className: "primary", type: "submit" }, h(Icon, { id: "lock" }), " Update Password"))
      )
    ),
    h("section", { className: "card" },
      h("div", { className: "section-title" }, h("h2", null, place), h("span", { className: "badge" }, "Barangay hall")),
      h("p", { className: "section-note" }, "Office contact information is managed by barangay staff."),
      h("div", { className: "profile-meta" },
        profileInfo("Where to go", office.address || "Ask the barangay hall for the address"),
        profileInfo("Open hours", office.officeHours || "Monday to Friday, 8:00 AM to 5:00 PM"),
        profileInfo("Hall contact", office.contact || "Ask at the barangay hall"),
        profileInfo("Hall email", office.email || "Not posted yet"),
        profileInfo("Punong Barangay", office.punongBarangay || "Not posted yet")
      )
    )
  );
}

function settingsField(label, value, onChange, options = {}) {
  return h("label", { className: `field ${options.wide ? "span-2" : ""}` },
    h("span", null, label),
    h("input", {
      className: "input",
      type: options.type || "text",
      value: value || "",
      placeholder: options.placeholder || "",
      required: Boolean(options.required),
      onChange: (event) => onChange(event.target.value),
    })
  );
}

function Settings({ actions, readOnly = false }) {
  const settings = { ...createDefaultState().settings, ...(actions.data.settings || {}) };
  const [form, setForm] = useState(settings);
  if (actions.session.role === "user") return h(ResidentSettings, { actions });
  if (readOnly) {
    const fields = [
      ["Barangay name", settings.barangayName],
      ["City", settings.city],
      ["Province", settings.province],
      ["Region", settings.region],
      ["Barangay hall address", settings.address],
      ["Office contact number", settings.contact],
      ["Office email", settings.email],
      ["Office hours", settings.officeHours],
      ["Punong Barangay", settings.punongBarangay],
    ];
    return h("section", { className: "card" },
      h("div", { className: "section-title" },
        h("div", null, h("h2", null, "Barangay office"), h("p", { className: "section-note" }, "Office information is available for monitoring only.")),
        h("span", { className: "badge ok" }, "Read only")
      ),
      h("div", { className: "grid" }, fields.map(([label, value]) => h("div", { className: "list-item", key: label }, h("b", null, label), h("span", null, value || "Not set"))))
    );
  }

  function update(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function saveSettings(event) {
    event.preventDefault();
    const office = {
      barangayName: form.barangayName.trim(),
      city: form.city.trim(),
      province: form.province.trim(),
      region: form.region.trim(),
      address: form.address.trim(),
      contact: form.contact.trim(),
      email: form.email.trim(),
      officeHours: form.officeHours.trim(),
      punongBarangay: form.punongBarangay.trim(),
    };
    if (!office.barangayName || !office.city) {
      actions.notify("Barangay name and city are required.");
      return;
    }
    const next = actions.logAction(roles[actions.session.role].name, "Updated barangay office settings", { ...actions.data, settings: office });
    const saved = await actions.save(next);
    if (saved) {
      setForm(office);
      actions.notify("Barangay office settings saved.");
    }
  }

  return h(
    "form",
    { className: "form-panel", onSubmit: saveSettings },
    h("div", { className: "section-title" }, h("div", null, h("h2", null, "Barangay office"), h("p", { className: "section-note" }, "These details identify the office on reports and in the workspace.")), h("span", { className: "badge ok" }, "Saved to Database")),
    h("div", { className: "form-grid" },
      settingsField("Barangay name", form.barangayName, (value) => update("barangayName", value), { required: true }),
      settingsField("City", form.city, (value) => update("city", value), { required: true }),
      settingsField("Province", form.province, (value) => update("province", value), { required: true }),
      settingsField("Region", form.region, (value) => update("region", value)),
      settingsField("Barangay hall address", form.address, (value) => update("address", value), { wide: true }),
      settingsField("Office contact number", form.contact, (value) => update("contact", value), { type: "tel", placeholder: "09XX XXX XXXX" }),
      settingsField("Office email", form.email, (value) => update("email", value), { type: "email", placeholder: "office@barangay.gov.ph" }),
      settingsField("Office hours", form.officeHours, (value) => update("officeHours", value), { wide: true }),
      settingsField("Punong Barangay", form.punongBarangay, (value) => update("punongBarangay", value), { wide: true, placeholder: "Name of the Punong Barangay" }),
      h("button", { className: "primary span-2", type: "submit" }, "Save Settings")
    )
  );
}

//#endregion
//#region Reusable form fields and modal workflows
function input(label, value, extraClass = "") {
  return h("label", { className: `field ${extraClass}` }, h("span", null, label), h("input", { className: "input", defaultValue: value }));
}

function controlledInput(label, value, onChange, type = "text", extraClass = "") {
  const inputProps = { className: "input", value, onChange: (event) => onChange(event.target.value), required: label.includes("Full Name") || label.includes("Current") || label.includes("New") || label.includes("Confirm") };
  return h("label", { className: `field ${extraClass}` }, h("span", null, label), type === "password" ? h(PasswordInput, inputProps) : h("input", { ...inputProps, type }));
}

function BenefitTypePicker({ selected, otherOpen, otherText, onChange, onOtherOpen, onOtherText, legend = "Benefit types", note = "Check every benefit this person belongs to. The checked types are the ones saved on this record.", otherLabel = "Specific benefit type" }) {
  function toggle(type) {
    onChange(selected.includes(type) ? selected.filter((item) => item !== type) : [...selected, type]);
  }

  const allSelected = standardBenefitTypes.every((type) => selected.includes(type));
  const customNames = otherOpen ? specificBenefitNames(otherText) : [];
  const chosen = [...selected, ...customNames];

  return h("fieldset", { className: "benefit-picker" },
    h("legend", null, legend),
    h("p", { className: "benefit-note" }, note),
    h("label", { className: `benefit-option benefit-all ${allSelected ? "selected" : ""}` },
      h("input", { type: "checkbox", checked: allSelected, onChange: () => onChange(allSelected ? [] : [...standardBenefitTypes]) }),
      h("span", null, allSelected ? "Clear all" : "Select all")
    ),
    h("div", { className: "benefit-grid" }, beneficiaryCategories.map((type) => h("label", { className: `benefit-option ${(type === "Other" ? otherOpen : selected.includes(type)) ? "selected" : ""}`, key: type },
      h("input", { type: "checkbox", name: "categories", value: type, checked: type === "Other" ? otherOpen : selected.includes(type), onChange: () => (type === "Other" ? onOtherOpen(!otherOpen) : toggle(type)) }),
      h("span", null, type)
    ))),
    otherOpen && h("label", { className: "benefit-other" },
      h("span", null, otherLabel),
      h("input", {
        className: "input",
        value: otherText,
        placeholder: "Example: Fisherfolk, Informal settler",
        onChange: (event) => onOtherText(event.target.value),
      })
    ),
    h("p", { className: "benefit-selected" }, chosen.length ? `Selected: ${chosen.join(", ")}` : "No benefit type selected yet.")
  );
}

function ResidentRoleField() {
  return h("label", { className: "field", key: "resident-role" },
    h("span", null, "Role"),
    h("input", { className: "input role-lock", value: "Resident Account", readOnly: true, "aria-label": "Role" }),
    h("input", { type: "hidden", name: "role", value: "User" })
  );
}

function Modal({ modal, actions, close }) {
  const modalType = typeof modal === "string" ? modal : modal.type;
  const record = typeof modal === "string" ? null : modal.record;
  const residentAccountForm = modalType === "user" && actions.session.role === "secretary";
  const staffAccountForm = modalType === "staff" && actions.session.role === "barangay";
  const adminCaptainAccountForm = modalType === "staff" && actions.session.role === "admin";
  const [userRole, setUserRole] = useState(accountRoleFormValue(record?.role || "User"));
  const initialBeneficiary = actions.data.beneficiaries.find((beneficiary) => beneficiary.name === record?.beneficiaryName) || actions.data.beneficiaries[0];
  const [linkedName, setLinkedName] = useState(record?.beneficiaryName || initialBeneficiary?.name || "");
  const benefitSource = record ? actions.data.beneficiaries.find((beneficiary) => beneficiary.name === (record.beneficiaryName || record.name)) : null;
  const initialBenefits = readBenefitForm(benefitSource?.category);
  const [selectedBenefits, setSelectedBenefits] = useState(initialBenefits.standard);
  const [otherOpen, setOtherOpen] = useState(initialBenefits.otherOpen);
  const [otherText, setOtherText] = useState(initialBenefits.otherText);
  const [assistanceBenefitFilter, setAssistanceBenefitFilter] = useState("");
  const [assistanceBenefitOther, setAssistanceBenefitOther] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const programForm = modalType === "program" || modalType === "programEdit";
  const existingProgramBenefit = modalType === "programEdit" ? String(record?.benefit || "").trim() : "";
  const matchedProgramBenefit = knownProgramBenefit(existingProgramBenefit);
  const initialProgramBenefit = modalType === "programEdit"
    ? matchedProgramBenefit || (existingProgramBenefit ? "Others" : "")
    : "";
  const [programBenefit, setProgramBenefit] = useState(initialProgramBenefit);
  const [programBenefitOther, setProgramBenefitOther] = useState(matchedProgramBenefit ? "" : existingProgramBenefit);
  const initialProgramStatus = programStatuses.includes(record?.status) ? record.status : "Scheduled";
  const [programStatus, setProgramStatus] = useState(initialProgramStatus);
  let activeForm = null;

  function programBenefitValue(values) {
    const choice = String(values.benefit || "").trim();
    if (!choice) {
      actions.notify("Choose a benefit category for this schedule.");
      return "";
    }
    if (choice !== "Others") return choice;
    const specific = String(values.benefitOther || "").trim();
    if (!specific) {
      actions.notify("Enter the specific benefit.");
      return "";
    }
    if (specific.length > 80) {
      actions.notify("The specific benefit is too long.");
      return "";
    }
    return specific;
  }

  function applyBenefitForm(value) {
    const next = readBenefitForm(value);
    setSelectedBenefits(next.standard);
    setOtherOpen(next.otherOpen);
    setOtherText(next.otherText);
  }

  function chooseBeneficiary(name) {
    setLinkedName(name);
    const match = actions.data.beneficiaries.find((beneficiary) => beneficiary.name === name);
    applyBenefitForm(match?.category);
  }

  function currentBenefits() {
    const checked = activeForm ? [...activeForm.querySelectorAll('input[name="categories"]:checked')].map((input) => input.value).filter((type) => type !== "Other") : [];
    const custom = otherOpen ? specificBenefitNames(otherText) : [];
    if (otherOpen && !custom.length) {
      actions.notify("Enter the specific benefit type.");
      return null;
    }
    const types = [];
    [...selectedBenefits, ...checked, ...custom].forEach((type) => {
      const name = String(type || "").trim();
      if (!name || types.some((item) => item.toLowerCase() === name.toLowerCase())) return;
      types.push(name);
    });
    if (!types.length) {
      actions.notify("Select at least one benefit type.");
      return null;
    }
    if (types.join(", ").length > 255) {
      actions.notify("The benefit types are too long.");
      return null;
    }
    return types;
  }
  const configs = {
    beneficiary: {
      title: "Add Beneficiary",
      fields: [["name", "Full Name"], ["purok", "Purok"], ["contact", "Contact Number"]],
      async submit(values) {
        const types = currentBenefits();
        if (!types) return false;
        const category = types.join(", ");
        const assistance = syncAssistanceWithCategory(actions.data.assistance, values.name, "", category, roles[actions.session.role].name);
        const next = { ...actions.data, assistance, beneficiaries: [...actions.data.beneficiaries, { id: `B-2026-${String(actions.data.beneficiaries.length + 1).padStart(3, "0")}`, name: values.name, purok: values.purok, category, contact: values.contact, status: "Active", lastAid: types.join(", "), updated: today() }] };
        const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Added beneficiary ${values.name}`, next));
        if (!saved) return false;
        actions.notify("Beneficiary record saved.");
        return true;
      },
    },
    beneficiaryEdit: {
      title: "Edit Beneficiary",
      fields: [["name", "Full Name", null, record?.name || ""], ["purok", "Purok", null, record?.purok || ""], ["contact", "Contact Number", null, record?.contact || ""], ["status", "Status", ["Active", "Pending"], record?.status || "Active"]],
      async submit(values) {
        const types = currentBenefits();
        if (!types) return false;
        const category = types.join(", ");
        const assistance = syncAssistanceWithCategory(actions.data.assistance, values.name, record.category, category, roles[actions.session.role].name).map((item) => String(item.beneficiary || "").trim().toLowerCase() === String(record.name || "").trim().toLowerCase() ? { ...item, beneficiary: values.name } : item);
        const beneficiaries = actions.data.beneficiaries.map((b) => b.id === record.id ? { ...b, ...values, category, lastAid: types.join(", ") || b.lastAid, updated: today() } : b);
        const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Edited beneficiary ${values.name}`, { ...actions.data, beneficiaries, assistance }));
        if (!saved) return false;
        actions.notify("Beneficiary record updated.");
        return true;
      },
    },
    assistance: {
      title: "Record Assistance",
      fields: [["benefitFilter", "Benefit Type", []], ["type", "Assistance Type", null, "Food Pack"], ["amount", "Planned Amount / Items for Each Resident", null, "1 package"], ["date", "Planned Release Date", "date", today()], ["status", "Status", ["Pending", "Completed"]], ["staff", "Staff in Charge", null, roles[actions.session.role].name]],
      async submit(values) {
        const selectedBenefit = String(values.benefitFilter || "") === customAssistanceBenefitOption
          ? String(values.benefitFilterOther || "").trim()
          : String(values.benefitFilter || "").trim();
        if (!selectedBenefit) {
          actions.notify(values.benefitFilter === customAssistanceBenefitOption
            ? "Enter the specific benefit used on the resident account."
            : "Choose a benefit type before scheduling assistance.");
          return false;
        }
        if (selectedBenefit.length > 80) {
          actions.notify("The specific benefit is too long.");
          return false;
        }
        const matchingBeneficiaries = actions.data.beneficiaries.filter((beneficiary) =>
          String(beneficiary.status || "").toLowerCase() === "active"
          && categoryList(beneficiary.category).some((category) => category.toLowerCase() === selectedBenefit.toLowerCase())
        );
        if (!matchingBeneficiaries.length) {
          actions.notify(`No active residents are registered under ${selectedBenefit}.`);
          return false;
        }
        const usedIds = new Set(actions.data.assistance.map((item) => String(item.id)));
        let nextId = 5001;
        const assistanceRecords = matchingBeneficiaries.map((beneficiary) => {
          while (usedIds.has(`A-${nextId}`)) {
            nextId += 1;
          }
          const id = `A-${nextId}`;
          usedIds.add(id);
          nextId += 1;

          return {
            id,
            beneficiary: beneficiary.name,
            type: values.type,
            amount: values.amount,
            date: values.date,
            status: values.status,
            staff: values.staff,
          };
        });
        const matchingNames = new Set(matchingBeneficiaries.map((beneficiary) => beneficiary.name));
        const beneficiaries = actions.data.beneficiaries.map((beneficiary) => matchingNames.has(beneficiary.name)
          ? { ...beneficiary, lastAid: values.type, updated: values.date }
          : beneficiary
        );
        const next = { ...actions.data, beneficiaries, assistance: [...assistanceRecords, ...actions.data.assistance] };
        const saved = await actions.save(actions.logAction(
          roles[actions.session.role].name,
          `Scheduled ${values.type} for ${matchingBeneficiaries.length} ${selectedBenefit} residents`,
          next
        ));
        if (!saved) return false;
        actions.notify(`Release schedule saved for ${matchingBeneficiaries.length} ${selectedBenefit} residents.`);
        return true;
      },
    },
    assistanceScheduleEdit: {
      title: "Edit Resident Release Schedule",
      fields: [
        ["date", "Planned Release Date", "date", record?.date || today()],
        ["amount", "Planned Amount / Items for this Resident (optional)", null, record?.amount === "As released" ? "" : record?.amount || ""],
      ],
      async submit(values) {
        const assistance = actions.data.assistance.map((item) =>
          item.id === record.id ? { ...item, date: values.date, amount: String(values.amount || "").trim() } : item
        );
        const saved = await actions.save(actions.logAction(
          roles[actions.session.role].name,
          `Updated the assistance release schedule for ${record.beneficiary}`,
          { ...actions.data, assistance }
        ));
        if (!saved) return false;
        actions.notify("Resident release date and planned amount saved.");
        return true;
      },
    },
    program: {
      title: "Add Program Schedule",
      fields: [["name", "Program"], ["benefit", "Benefit", programBenefitChoices, ""], ["date", "Date", "date"], ["time", "Time", "time"], ["venue", "Venue"], ["assignedStaff", "Assigned Staff"], ["releaseAmount", "Amount / Items", null, ""], ["instructions", "Resident Instructions", null, ""], ["status", "Status", programStatuses]],
      async submit(values) {
        const benefit = programBenefitValue(values);
        if (!benefit) return false;
        const { benefitOther, ...programValues } = values;
        const next = { ...actions.data, programs: [...actions.data.programs, { id: `P-${101 + actions.data.programs.length}`, ...programValues, benefit }] };
        const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Added schedule ${values.name}`, next));
        if (!saved) return false;
        actions.notify("Program schedule saved.");
        return true;
      },
    },
    programEdit: {
      title: "Edit Program Schedule",
      fields: [["name", "Program", null, record?.name || ""], ["benefit", "Benefit", programBenefitChoices, record?.benefit || ""], ["date", "Date", "date", record?.date || today()], ["time", "Time", "time", record?.time || ""], ["venue", "Venue", null, record?.venue || ""], ["assignedStaff", "Assigned Staff", null, record?.assignedStaff || ""], ["releaseAmount", "Amount / Items", null, record?.releaseAmount || ""], ["instructions", "Resident Instructions", null, record?.instructions || ""], ["status", "Status", programStatuses]],
      async submit(values) {
        const benefit = programBenefitValue(values);
        if (!benefit) return false;
        const { benefitOther, ...programValues } = values;
        const programs = actions.data.programs.map((program) => program.id === record.id ? { ...program, ...programValues, benefit } : program);
        const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Edited program schedule ${values.name}`, { ...actions.data, programs }));
        if (!saved) return false;
        actions.notify("Program schedule updated.");
        return true;
      },
    },
    user: {
      title: "Create Resident Account",
      fields: [
        ["name", "Full Name"],
        ["residentRole", "Role"],
        ["username", "Username"],
        ["password", "Password", "password", ""],
        ["email", "Email address (optional)", "email"],
        ["contact", "Contact Number (optional)"],
        ["address", "Address"],
      ],
      async submit(values) {
        const duplicate = actions.data.users.some((user) => String(user.username || "").trim().toLowerCase() === values.username.trim().toLowerCase());
        if (duplicate) {
          actions.notify("Username already exists. Please choose another username.");
          return false;
        }
        const role = residentAccountForm ? "User" : values.role;
        const beneficiary = role === "User" && !residentAccountForm ? actions.data.beneficiaries.find((item) => item.name === (values.beneficiaryName || linkedName)) : null;
        if (role === "User" && !residentAccountForm && !beneficiary) {
          actions.notify("Choose a beneficiary record before creating this account.");
          return false;
        }
        const benefitTypes = residentAccountForm ? currentBenefits() : null;
        if (residentAccountForm && !benefitTypes) return false;
        if (residentAccountForm) {
          const result = await window.BarangayCareService.createResident({
            name: values.name,
            username: values.username,
            password: values.password,
            email: values.email,
            contact: values.contact,
            address: values.address,
            categories: benefitTypes,
          });
          await actions.refreshData();
          actions.notify(`Resident account created. Sign in with username ${result.user.username} to open the resident dashboard.`);
          return true;
        }
        const category = benefitTypes ? benefitTypes.join(", ") : beneficiary?.category || "";
        const assistance = beneficiary && benefitTypes ? syncAssistanceWithCategory(actions.data.assistance, beneficiary.name, beneficiary.category, category, roles[actions.session.role].name) : actions.data.assistance;
        const beneficiaries = beneficiary && benefitTypes ? actions.data.beneficiaries.map((item) => item.id === beneficiary.id ? { ...item, category, lastAid: benefitTypes.join(", ") || item.lastAid, updated: today() } : item) : actions.data.beneficiaries;
        const next = { ...actions.data, assistance, beneficiaries, users: [...actions.data.users, { name: values.name, role: values.role, beneficiaryName: beneficiary?.name || "", username: values.username, password: values.password, email: values.email, contact: values.contact, address: values.address, status: values.status }] };
        const saved = await actions.save(actions.logAction(roles[actions.session.role].name, `Added user account ${values.username}`, next));
        if (!saved) return false;
        actions.notify("Resident account saved. It can now log in.");
        return true;
      },
    },
    staff: {
      title: adminCaptainAccountForm ? "Create Barangay Captain Account" : "Create Staff Account",
      fields: [
        ["name", "Full Name"],
        ["staffRole", adminCaptainAccountForm ? "Account Role" : "Staff Role", adminCaptainAccountForm ? ["Barangay Captain"] : ["Secretary", "Assistance Officer", "Distribution Staff"]],
        ["username", "Username"],
        ["password", "Password (at least 8 characters)", "password"],
        ["email", "Email address (optional)", "email"],
        ["contact", "Contact Number (optional)"],
        ["position", "Position (optional)"],
      ],
      async submit(values) {
        const result = await window.BarangayCareService.createStaffAccount({
          ...values,
          role: adminCaptainAccountForm ? "Barangay Captain" : values.staffRole,
        });
        await actions.refreshData();
        actions.notify(`${accountRoleLabel(result.user.role)} account created. Share the username ${result.user.username} and password securely with the account holder.`);
        return true;
      },
    },
    userEdit: {
      title: "Edit Resident Account",
      fields: [
        ["name", "Full Name", null, record?.name || ""],
        ["username", "Username", null, record?.username || ""],
        ["password", "New Password (leave blank to keep current)", "password", ""],
        ["email", "Email address (optional)", "email", record?.email || ""],
        ["contact", "Contact Number (optional)", null, record?.contact || ""],
        ["address", "Address (optional)", null, record?.address || ""],
        ["status", "Status", ["Active", "Inactive"], record?.status === "Inactive" ? "Inactive" : "Active"],
      ],
      async submit(values) {
        await window.BarangayCareService.updateResident(record.id, values);
        await actions.refreshData();
        actions.notify("Resident account updated.");
        return true;
      },
    },
    staffPasswordReset: {
      title: `Reset Password — ${record?.name || "Staff Account"}`,
      fields: [
        ["newPassword", "Temporary Password (at least 12 characters)", "password"],
        ["newPassword_confirmation", "Confirm Temporary Password", "password"],
      ],
      async submit(values) {
        await window.BarangayCareService.resetStaffAccountPassword(
          record.id,
          values.newPassword,
          values.newPassword_confirmation
        );
        await actions.refreshData();
        actions.notify(`Password reset for ${record.name}. Share the temporary password privately; they must change it at next sign-in.`);
        return true;
      },
    },
  };
  const showBenefitPicker = residentAccountForm || modalType === "beneficiary" || modalType === "beneficiaryEdit";
  const editingBeneficiary = modalType === "beneficiary" || modalType === "beneficiaryEdit";
  const benefitPicker = h(BenefitTypePicker, {
    key: "benefits",
    legend: editingBeneficiary ? "Assistance" : "Benefit types",
    note: editingBeneficiary
      ? "Check the assistance for this resident. The resident account with the same name sees it on My Assistance."
      : "Check every benefit this person belongs to. The checked types are the ones saved on this record.",
    otherLabel: editingBeneficiary ? "Specific assistance" : "Specific benefit type",
    selected: selectedBenefits,
    otherOpen,
    otherText,
    onChange: setSelectedBenefits,
    onOtherOpen: setOtherOpen,
    onOtherText: setOtherText,
  });
  const config = configs[modalType];
  const activeAssistanceBeneficiaries = actions.data.beneficiaries.filter((beneficiary) =>
    String(beneficiary.status || "").toLowerCase() === "active"
  );
  const assistanceBenefitOptions = [...new Set(activeAssistanceBeneficiaries.flatMap((beneficiary) => categoryList(beneficiary.category)))]
    .sort((first, second) => {
      const firstIndex = standardBenefitTypes.findIndex((type) => type.toLowerCase() === first.toLowerCase());
      const secondIndex = standardBenefitTypes.findIndex((type) => type.toLowerCase() === second.toLowerCase());
      const firstRank = firstIndex === -1 ? standardBenefitTypes.length : firstIndex;
      const secondRank = secondIndex === -1 ? standardBenefitTypes.length : secondIndex;
      return firstRank - secondRank || first.localeCompare(second);
    });
  const selectedAssistanceBenefit = assistanceBenefitFilter === customAssistanceBenefitOption
    ? assistanceBenefitOther.trim()
    : assistanceBenefitFilter;
  const matchingAssistanceBeneficiaries = activeAssistanceBeneficiaries.filter((beneficiary) =>
    selectedAssistanceBenefit
    && categoryList(beneficiary.category).some((category) => category.toLowerCase() === selectedAssistanceBenefit.toLowerCase())
  );
  const beneficiaryOptions = actions.data.beneficiaries.map((beneficiary) => ({ value: beneficiary.name, label: beneficiary.name }));
  const linkedBeneficiaryField = ["beneficiaryName", "Linked beneficiary", beneficiaryOptions, linkedName, (event) => chooseBeneficiary(event.target.value)];
  const fields = config.fields.flatMap((field) => {
    if (modalType === "assistance" && field[0] === "benefitFilter") {
      return [[
        field[0],
        field[1],
        [
          { value: "", label: "Choose benefit type" },
          ...assistanceBenefitOptions.map((benefit) => ({ value: benefit, label: benefit })),
          { value: customAssistanceBenefitOption, label: "Others" },
        ],
        assistanceBenefitFilter,
        (event) => {
          setAssistanceBenefitFilter(event.target.value);
          if (event.target.value !== customAssistanceBenefitOption) setAssistanceBenefitOther("");
        },
      ], ...(assistanceBenefitFilter === customAssistanceBenefitOption
        ? [["benefitFilterOther", "Specific benefit type", null, assistanceBenefitOther]]
        : [])];
    }
    if (field[0] === "residentRole") return [field];
    if (field[0] !== "role") return [field];
    const roleField = [field[0], field[1], field[2], field[3] || accountRoleFormValue(record?.role || "User"), (event) => setUserRole(event.target.value)];
    return userRole === "User" ? [roleField, linkedBeneficiaryField] : [roleField];
  });
  const selectedProgramBenefit = programBenefit === "Others" ? programBenefitOther.trim() : programBenefit;
  const matchingProgramProfiles = selectedProgramBenefit
    ? actions.data.beneficiaries.filter((beneficiary) =>
      String(beneficiary.status || "").toLowerCase() === "active"
      && (selectedProgramBenefit.toLowerCase() === "all residents"
        || categoryList(beneficiary.category).some((category) => category.toLowerCase() === selectedProgramBenefit.toLowerCase()))
    ).length
    : 0;
  return h("div", { className: "modal-backdrop" }, h("form", {
    className: "modal",
    onSubmit: async (event) => {
      event.preventDefault();
      if (submitting) return;
      setSubmitting(true);
      try {
        activeForm = event.currentTarget;
        const values = Object.fromEntries(new FormData(event.currentTarget).entries());
        const didSave = await config.submit(values);
        if (didSave !== false) close();
      } catch (error) {
        actions.notify(`Laravel action failed: ${error.message}`);
      } finally {
        setSubmitting(false);
      }
    },
  },
  h("div", { className: "modal-head" }, h("h2", null, config.title), h("button", { className: "x", type: "button", onClick: close, disabled: submitting, "aria-label": "Close" }, "x")),
  h("div", { className: "modal-body" },
  h("div", { className: "notice" }, modalType === "assistance"
    ? "Choose a benefit type to schedule this assistance for every active resident with that benefit."
    : residentAccountForm
    ? "The role is Resident Account. Enter the resident's details and check every benefit type that person belongs to."
    : adminCaptainAccountForm
      ? "Create an active Barangay Captain account. Admin accounts cannot create other staff or resident account types."
    : staffAccountForm
      ? "Create an active account for authorized staff. Choose a secure password and share it privately."
    : modalType === "staffPasswordReset"
      ? "Set a temporary password of at least 12 characters and share it privately with this staff member. Their current sessions will be signed out, and they must choose a new password at their next sign-in."
    : modalType === "userEdit"
      ? "Update resident access and contact details. Leave the password blank to keep the current password."
    : programForm
      ? "This audience controls which residents can see the schedule and who appears as eligible in the distribution monitor. Set individual allocations in Social Assistance; this schedule amount is only a fallback. Choose All residents only when the program is open to everyone."
    : modalType === "assistanceScheduleEdit"
      ? "Update the resident-specific release date and planned amount. Distribution Staff and the resident will see the saved schedule."
    : modalType === "beneficiaryEdit"
      ? "Update this resident's details and assistance. The assistance checked below is what that resident sees on My Assistance."
      : modalType === "beneficiary"
        ? "Enter the resident's details and check the assistance they should see on My Assistance."
        : "Complete the required fields, then save. Dashboards and reports update automatically."),
  h("div", { className: "form-grid modal-fields" }, fields.flatMap((field) => {
    if (modalType === "assistance" && field[0] === "benefitFilterOther") {
      return [h("label", { className: "field program-benefit-other", key: field[0] },
        h("span", null, field[1]),
        h("input", {
          className: "input",
          name: field[0],
          value: assistanceBenefitOther,
          required: true,
          maxLength: 80,
          placeholder: "Enter the benefit exactly as on the resident account",
          onChange: (event) => setAssistanceBenefitOther(event.target.value),
        })
      )];
    }
    if (field[0] === "residentRole") {
      return [h(ResidentRoleField), benefitPicker];
    }
    if (field[0] === "benefit" && programForm) {
      return [
        h("label", { className: "field", key: "benefit" },
          h("span", null, "Eligible benefit category"),
          h("select", { className: "select", name: "benefit", value: programBenefit, required: true, onChange: (event) => setProgramBenefit(event.target.value) }, programBenefitChoices.map((option) => h("option", { key: option || "choose", value: option }, option || "Choose a benefit category")))
        ),
        programBenefit === "Others" ? h("label", { className: "field program-benefit-other", key: "benefitOther" },
          h("span", null, "Specific benefit"),
          h("input", { className: "input", name: "benefitOther", value: programBenefitOther, maxLength: 80, placeholder: "Example: Cash Assistance", onChange: (event) => setProgramBenefitOther(event.target.value) })
        ) : null,
        h("div", {
          key: "program-audience-preview",
          className: `program-audience-preview${selectedProgramBenefit && matchingProgramProfiles === 0 ? " is-empty" : ""}`,
          "aria-live": "polite",
        },
        selectedProgramBenefit
          ? h(React.Fragment, null,
            h("strong", { className: "program-audience-count" }, String(matchingProgramProfiles)),
            h("span", null, `${matchingProgramProfiles === 1 ? "active beneficiary profile" : "active beneficiary profiles"} will appear in this program's audience.`),
            matchingProgramProfiles === 0 && h("small", null, "You can still save this schedule. No active profile currently matches this category.")
          )
          : h("span", null, programBenefit === "Others" ? "Enter a specific benefit above to preview the matching audience." : "Choose a benefit category to preview the matching audience.")
        ),
      ];
    }
    if (field[0] === "status" && programForm) {
      return [
        h("label", { className: "field", key: "status" },
          h("span", null, "Status"),
          h("select", {
            className: "select",
            name: "status",
            value: programStatus,
            required: true,
            onChange: (event) => setProgramStatus(event.target.value),
          }, programStatuses.map((status) => h("option", { key: status, value: status }, status)))
        ),
      ];
    }
    return [modalField(field)];
  }), showBenefitPicker && !residentAccountForm ? benefitPicker : null),
  modalType === "assistance" && h("div", {
    className: `notice assistance-benefit-audience${selectedAssistanceBenefit && matchingAssistanceBeneficiaries.length === 0 ? " is-empty" : ""}`,
    "aria-live": "polite",
  }, selectedAssistanceBenefit
    ? matchingAssistanceBeneficiaries.length
      ? `This schedule will be shown to ${matchingAssistanceBeneficiaries.length} active residents with benefit ${selectedAssistanceBenefit}.`
      : `No active residents are registered under ${selectedAssistanceBenefit}.`
    : assistanceBenefitFilter === customAssistanceBenefitOption
      ? "Enter the specific benefit exactly as it appears on the resident account."
      : "Select a benefit type to preview who will see this schedule."),
  h("div", { className: "actions" }, h("button", { className: "primary", type: "submit", disabled: submitting || (modalType === "assistance" && (!selectedAssistanceBenefit || matchingAssistanceBeneficiaries.length === 0)) }, submitting ? "Saving..." : programForm ? (modalType === "programEdit" ? "Update Schedule" : "Save Schedule") : modalType === "assistanceScheduleEdit" ? "Update Release Schedule" : "Save Record"), h("button", { className: "secondary", type: "button", onClick: close, disabled: submitting }, "Cancel")))));
}

function modalField([name, label, optionsOrType, defaultValue = "", onChange]) {
  if (name === "instructions") {
    return h("label", { className: "field span-2", key: name }, h("span", null, label), h("textarea", { className: "input", name, rows: 3, maxLength: 1000, defaultValue }));
  }
  if (Array.isArray(optionsOrType)) {
    const first = optionsOrType[0];
    const fallback = first ? (typeof first === "object" ? first.value : first) : "";
    const currentValue = defaultValue || fallback;
    const selectProps = { className: "select", name, onChange, required: optionsOrType.length > 0 };
    if (onChange) selectProps.value = currentValue;
    else selectProps.defaultValue = currentValue;
    return h("label", { className: "field", key: name }, h("span", null, label), h("select", selectProps, optionsOrType.map((option) => { const item = typeof option === "object" ? option : { value: option, label: option }; return h("option", { key: item.value, value: item.value }, item.label); })));
  }
  const passwordOptional = name === "password" && /leave blank/i.test(String(label));
  const inputProps = { className: "input", name, defaultValue, required: !/optional/i.test(String(label)) && !passwordOptional, minLength: name === "password" ? 8 : ["newPassword", "newPassword_confirmation"].includes(name) ? 12 : undefined, maxLength: ["amount", "releaseAmount"].includes(name) ? 120 : undefined };
  return h("label", { className: "field", key: name }, h("span", null, label), optionsOrType === "password" ? h(PasswordInput, inputProps) : h("input", { ...inputProps, type: optionsOrType || "text" }));
}

//#endregion

root.render(h(App));
