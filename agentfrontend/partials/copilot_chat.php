<?php
// Minimal CopilotKit chat embed via ESM (no build step).
// Requirements: tool_server.py running on localhost:8891 (for tools) and Ollama on 11434.
?>
<div class="section-full" id="copilot-container" style="background:#0e1626;border:1px solid #223047;border-radius:12px;padding:12px;">
  <h3 style="color:#8bd3ff;margin-top:0;">محادثة CopilotKit (تجريبي)</h3>
  <p style="color: var(--text-secondary);">تكتب هنا، النموذج يرد ويبث الأدوات تلقائياً.</p>
  <div id="copilot-root"></div>
</div>
<script type="module">
  import React from "https://esm.sh/react@18.2.0";
  import ReactDOM from "https://esm.sh/react-dom@18.2.0/client";
  import * as CopilotCore from "https://esm.sh/@copilotkit/react-core@0.4.0";
  import * as CopilotUI from "https://esm.sh/@copilotkit/react-ui@0.4.0";

  const root = ReactDOM.createRoot(document.getElementById("copilot-root"));

  const CopilotKit = CopilotCore.CopilotKit || CopilotCore.CopilotProvider || CopilotCore.default;
  const CopilotTextarea = CopilotCore.CopilotTextarea || CopilotUI.CopilotTextarea || CopilotCore.CopilotTextArea;
  const useCopilotReadable = CopilotCore.useCopilotReadable || CopilotUI.useCopilotReadable || (() => {});
  const CopilotSidebar = CopilotUI.CopilotSidebar || CopilotUI.CopilotKitSidebar || (({ children }) => React.createElement("div", null, children));

  const App = () => {
    useCopilotReadable("context", "BGL3 dashboard active; tools available via tool_server.");
    return (
      React.createElement("div", { style: { display: "flex", gap: "12px" } },
        React.createElement(CopilotSidebar, {
          labels: {
            title: "المحادثة",
            subtitle: "اسأل أو اطلب فحص/إصلاح",
            inputPlaceholder: "اكتب هنا بالعربية...",
            send: "إرسال"
          }
        },
          React.createElement(CopilotTextarea, {
            placeholder: "مثال: شغّل run_checks وأعطني ملخصاً عربياً",
            autosize: true,
          })
        )
      )
    );
  };

  root.render(
    React.createElement(CopilotKit, {
      // Ollama OpenAI-compatible endpoint
      // e.g., http://localhost:11434/v1/chat/completions
      client: {
        provider: "openai",
        apiKey: "none",
        baseUrl: "http://localhost:11434/v1",
      },
      tools: [
        {
          name: "run_checks",
          description: "تشغيل فحوص النظام",
          parameters: { type: "object", properties: {}, required: [] },
          handler: async () => {
            const res = await fetch("http://localhost:8891/tool", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ tool: "run_checks" })
            });
            return await res.json();
          }
        },
        {
          name: "route_index",
          description: "إعادة فهرسة المسارات",
          parameters: { type: "object", properties: {}, required: [] },
          handler: async () => {
            const res = await fetch("http://localhost:8891/tool", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ tool: "route_index" })
            });
            return await res.json();
          }
        },
        {
          name: "logic_bridge",
          description: "تشغيل logic_bridge ببيانات JSON",
          parameters: {
            type: "object",
            properties: {
              candidates: { type: "array" },
              record: { type: "object" }
            },
            required: ["candidates", "record"]
          },
          handler: async (payload) => {
            const res = await fetch("http://localhost:8891/tool", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ tool: "logic_bridge", payload })
            });
            return await res.json();
          }
        }
      ]
    },
      React.createElement(App, null)
    )
  );
</script>
