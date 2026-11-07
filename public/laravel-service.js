(function () {
  const backend = window.BARANGAYCARE_BACKEND || { mode: "laravel", apiBase: "/api" };

  const emptyState = {
    beneficiaries: [],
    programs: [],
    assistance: [],
    applications: [],
    distributions: [],
    users: [],
    logs: [],
    messages: [],
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

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function token() {
    return window.sessionStorage.getItem("barangaycare.token") || "";
  }

  function normalizeState(data) {
    const source = data && typeof data === "object" ? data : {};
    return {
      beneficiaries: Array.isArray(source.beneficiaries) ? source.beneficiaries : [],
      programs: Array.isArray(source.programs) ? source.programs : [],
      assistance: Array.isArray(source.assistance) ? source.assistance : [],
      applications: Array.isArray(source.applications) ? source.applications : [],
      distributions: Array.isArray(source.distributions) ? source.distributions : [],
      users: Array.isArray(source.users) ? source.users.map((user) => ({
        status: "Active",
        beneficiaryName: "",
        email: "",
        profileImage: "",
        emergencyContact: "",
        ...user,
      })) : [],
      logs: Array.isArray(source.logs) ? source.logs : [],
      messages: Array.isArray(source.messages) ? source.messages : [],
      settings: {
        ...emptyState.settings,
        ...(source.settings || {}),
      },
      monitoring: source.monitoring && typeof source.monitoring === "object" ? source.monitoring : {},
      meta: source.meta && typeof source.meta === "object" ? source.meta : null,
    };
  }

  function assertLaravelBackend() {
    if (backend.mode !== "laravel") {
      throw new Error("BarangayCare is configured for Laravel only.");
    }
    if (!backend.apiBase) {
      throw new Error("Laravel API base path is missing.");
    }
  }

  async function apiRequest(path, options = {}) {
    assertLaravelBackend();
    const endpoint = `${backend.apiBase.replace(/\/+$/, "")}/${path.replace(/^\/+/, "")}`;
    const currentToken = token();
    const isMultipart = typeof FormData !== "undefined" && options.body instanceof FormData;
    const response = await fetch(endpoint, {
      ...options,
      headers: {
        Accept: "application/json",
        ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
        ...(options.body && !isMultipart ? { "Content-Type": "application/json" } : {}),
        ...(options.headers || {}),
      },
    });
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new Error(`Laravel returned an invalid response (${response.status}).`);
    }
    if (!response.ok || payload.ok === false) {
      const validationMessage = Object.values(payload.errors || {}).flat()[0];
      const error = new Error(payload.error || validationMessage || payload.message || "Laravel request failed.");
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  async function readRemote() {
    const payload = await apiRequest(token() ? "data" : "summary");
    return normalizeState(payload.data);
  }

  async function writeRemote(data) {
    const payload = await apiRequest("data", {
      method: "POST",
      body: JSON.stringify({ data }),
    });
    return normalizeState(payload.data);
  }

  async function health() {
    try {
      const payload = await apiRequest("health");
      return {
        ok: true,
        source: "laravel-mysql",
        message: `${payload.service} connected to ${payload.database}.`,
      };
    } catch (error) {
      return {
        ok: false,
        source: "laravel-error",
        message: error.message,
      };
    }
  }

  window.BarangayCareService = {
    mode: "Laravel/MySQL only",
    seed: clone(emptyState),
    health,
    async signIn(username, password) {
      if (!username || !password) throw new Error("Username and password are required.");
      return apiRequest("auth", {
        method: "POST",
        body: JSON.stringify({ username, password }),
      });
    },
    async createResident(account) {
      return apiRequest("residents", {
        method: "POST",
        body: JSON.stringify(account),
      });
    },
    async createStaffAccount(account) {
      return apiRequest("staff/accounts", {
        method: "POST",
        body: JSON.stringify(account),
      });
    },
    async updateStaffAccountStatus(id, status) {
      return apiRequest(`staff/accounts/${encodeURIComponent(id)}/status`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
    },
    async resetStaffAccountPassword(id, newPassword, newPasswordConfirmation) {
      return apiRequest(`staff/accounts/${encodeURIComponent(id)}/password`, {
        method: "PATCH",
        body: JSON.stringify({
          newPassword,
          newPassword_confirmation: newPasswordConfirmation,
        }),
      });
    },
    async deleteStaffAccount(id) {
      return apiRequest(`staff/accounts/${encodeURIComponent(id)}`, {
        method: "DELETE",
      });
    },
    async updateBeneficiaryVerification(id, status) {
      return apiRequest(`beneficiaries/${encodeURIComponent(id)}/verification`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
    },
    async updateResident(id, account) {
      return apiRequest(`residents/${encodeURIComponent(id)}`, {
        method: "PATCH",
        body: JSON.stringify(account),
      });
    },
    async updateProfile(profile) {
      return apiRequest("profile", {
        method: "POST",
        body: JSON.stringify(profile),
      });
    },
    async claimAssistance(id, release = {}) {
      return apiRequest("assistance/claim", {
        method: "POST",
        body: JSON.stringify({ id, ...release }),
      });
    },
    async requestAssistanceApplicationProof(id, note) {
      return apiRequest(`assistance/applications/${encodeURIComponent(id)}/proof-requests`, {
        method: "POST",
        body: JSON.stringify({ note }),
      });
    },
    async uploadAssistanceApplicationProof(id, files) {
      const body = new FormData();
      files.forEach((file) => body.append("proofs[]", file));
      return apiRequest(`assistance/applications/${encodeURIComponent(id)}/proofs`, {
        method: "POST",
        body,
      });
    },
    async downloadAssistanceApplicationProof(applicationId, documentId) {
      assertLaravelBackend();
      const endpoint = `${backend.apiBase.replace(/\/+$/, "")}/assistance/applications/${encodeURIComponent(applicationId)}/proofs/${encodeURIComponent(documentId)}/download`;
      const currentToken = token();
      const response = await fetch(endpoint, {
        headers: {
          Accept: "application/octet-stream",
          ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
        },
      });
      if (!response.ok) {
        let payload = {};
        try {
          payload = await response.json();
        } catch {
          payload = {};
        }
        const validationMessage = Object.values(payload.errors || {}).flat()[0];
        throw new Error(payload.error || validationMessage || payload.message || "Proof document download failed.");
      }
      return response.blob();
    },
    async reviewAssistanceApplication(id, status, reviewNote = "") {
      return apiRequest(`assistance/applications/${encodeURIComponent(id)}/review`, {
        method: "POST",
        body: JSON.stringify({ status, reviewNote }),
      });
    },
    async distributeAssistanceApplication(id, amount) {
      return apiRequest(`assistance/applications/${encodeURIComponent(id)}/distribute`, {
        method: "POST",
        body: JSON.stringify({ amount }),
      });
    },
    async changePassword(username, currentPassword, newPassword, newPasswordConfirmation) {
      return apiRequest("password", {
        method: "POST",
        body: JSON.stringify({
          username,
          currentPassword,
          newPassword,
          newPassword_confirmation: newPasswordConfirmation,
        }),
      });
    },
    async signOut() {
      try {
        await apiRequest("logout", { method: "POST" });
      } catch {
        // The session is already gone.
      }
      window.sessionStorage.removeItem("barangaycare.token");
      return true;
    },
    async loadData() {
      return readRemote();
    },
    async saveData(data) {
      const next = { ...data };
      delete next.meta;
      return writeRemote(next);
    },
    async version() {
      const payload = await apiRequest("version");
      return payload.version || "";
    },
    async backup() {
      const payload = await apiRequest("backup");
      return JSON.stringify(payload.data, null, 2);
    },
    async restore(data) {
      return apiRequest("backup/restore", {
        method: "POST",
        body: JSON.stringify({ data }),
      });
    },
    async sendStaffMessage(payload) {
      return apiRequest("messages", {
        method: "POST",
        body: JSON.stringify(payload),
      });
    },
    async requestPasswordReset(username, email) {
      return apiRequest("password/forgot", {
        method: "POST",
        body: JSON.stringify({
          username,
          email,
        }),
      });
    },
    async resetForgottenPassword(userId, token, password, passwordConfirmation) {
      return apiRequest("password/reset", {
        method: "POST",
        body: JSON.stringify({
          userId,
          token,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });
    },
    async chat(question, role, beneficiaryName) {
      const payload = await apiRequest("chat", {
        method: "POST",
        body: JSON.stringify({ question, role, beneficiaryName }),
      });
      return payload.reply;
    },
    async notifyProgram(programId) {
      try {
        return await apiRequest(`programs/${encodeURIComponent(programId)}/notify`, {
          method: "POST",
          body: JSON.stringify({}),
        });
      } catch (error) {
        return { ok: false, error: error.message };
      }
    },
    async notifyAssistance(record, eventType = "updated") {
      try {
        return await apiRequest("notify-assistance", {
          method: "POST",
          body: JSON.stringify({ record, eventType }),
        });
      } catch (error) {
        return { ok: false, error: error.message };
      }
    },
  };
})();
