import React, { useContext, useMemo, useState, useEffect } from "react";
import ReactDOM from "react-dom/client";
import { CopilotProvider, CopilotContext, useMakeCopilotActionable, useMakeCopilotReadable } from "@copilotkit/react-core";
import { useChat } from "ai/react";

// Polyfill بسيط لـ process في المتصفح لتفادي ReferenceError أثناء الـ IIFE
if (typeof globalThis.process === "undefined") {
  globalThis.process = { env: {} };
}

const TOOL_ENDPOINT = "http://localhost:8891/tool";
const CHAT_ENDPOINT = "http://localhost:8891/chat";

const defaultSystemMessage = () => `
أنت مساعد برمجي داخل لوحة تحكم BGL3.
العربية المختصرة فقط. لا تدّعِ فعلاً أو أرقاماً/مسارات بلا أداة.
اعتمد على السياق المحقون، وإن كان ناقصاً اطلب إعادة التحميل.
`;

function useBglCopilotChat({ id, makeSystemMessage = defaultSystemMessage }) {
  const { getContextString, getChatCompletionFunctionDescriptions, getFunctionCallHandler } = useContext(CopilotContext);
  const lastToolCallRef = React.useRef(false);

  const systemMessage = useMemo(() => {
    const contextString = getContextString();
    return { id: "system", role: "system", content: makeSystemMessage(contextString) };
  }, [getContextString, makeSystemMessage]);

  const functionDescriptions = useMemo(() => getChatCompletionFunctionDescriptions(), [getChatCompletionFunctionDescriptions]);

  const { messages, append, reload, stop, isLoading, input, setInput } = useChat({
    id,
    api: async ({ messages: msgs }) => {
      lastToolCallRef.current = false;
      const res = await fetch(CHAT_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          messages: msgs,
          functions: functionDescriptions,
          temperature: 0.3,
          target_url: window.location.href,
        }),
      });
      const data = await res.json();
      return { content: data.content ?? data.message ?? JSON.stringify(data) };
    },
    initialMessages: [systemMessage],
    experimental_onFunctionCall: (...args) => {
      lastToolCallRef.current = true;
      return getFunctionCallHandler()(...args);
    },
    body: {
      copilotkit_manually_passed_function_descriptions: functionDescriptions,
    },
  });

  const visibleMessages = messages.filter((m) => m.role === "assistant" || m.role === "user");

  return { visibleMessages, append, reload, stop, isLoading, input, setInput };
}

function ToolRegistry() {
  useMakeCopilotActionable(
    {
      name: "run_checks",
      description: "تشغيل فحوص النظام",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "run_checks" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "route_index",
      description: "إعادة فهرسة المسارات",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "route_index" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "logic_bridge",
      description: "تشغيل logic_bridge ببيانات JSON",
      argumentAnnotations: [
        { name: "candidates", description: "قائمة المرشحين", type: "array", required: true },
        { name: "record", description: "السجل المراد مطابقته", type: "object", required: true },
      ],
      implementation: async (candidates, record) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "logic_bridge", payload: { candidates, record } }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "context_snapshot",
      description: "جلب ملخص حي عن مجال النظام (الضمانات البنكية) من الملفات الداخلية",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "context_snapshot" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "phpunit_run",
      description: "تشغيل اختبارات PHPUnit مع فلتر اختياري",
      argumentAnnotations: [{ name: "filter", description: "اختياري: اسم اختبار/فلتر", type: "string", required: false }],
      implementation: async (filter) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "phpunit_run", filter }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "master_verify",
      description: "تشغيل master_verify (تشغيل شامل للتحقق)",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "master_verify" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "scenario_run",
      description: "تشغيل سيناريوهات المتصفح (run_scenarios)",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "scenario_run" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "log_tail",
      description: "إرجاع آخر أسطر من laravel.log",
      argumentAnnotations: [{ name: "lines", description: "عدد الأسطر (افتراضي 120)", type: "number", required: false }],
      implementation: async (lines) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "log_tail", lines }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "route_show",
      description: "إظهار ربط URI → Controller → Method لمسار معين",
      argumentAnnotations: [{ name: "uri", description: "المسار أو جزء منه", type: "string", required: true }],
      implementation: async (uri) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "route_show", uri }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "list_files",
      description: "قائمة بملفات المشروع (حد 200) مع فلتر نصي اختياري",
      argumentAnnotations: [
        { name: "limit", description: "عدد الملفات (افتراضي 20، أقصى 200)", type: "number", required: false },
        { name: "pattern", description: "نص للفلترة (اختياري)", type: "string", required: false },
      ],
      implementation: async (limit, pattern) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "list_files", limit, pattern }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "read_file",
      description: "قراءة جزء من ملف (حتى 4000 بايت) لمسار نسبي",
      argumentAnnotations: [
        { name: "path", description: "المسار النسبي للملف", type: "string", required: true },
        { name: "max_bytes", description: "الحد الأقصى للبايت (افتراضي 4000)", type: "number", required: false },
      ],
      implementation: async (path, max_bytes) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "read_file", path, max_bytes }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "search_code",
      description: "بحث نصي سريع داخل الملفات (حد 200 نتيجة)",
      argumentAnnotations: [
        { name: "pattern", description: "النص المراد البحث عنه", type: "string", required: true },
        { name: "limit", description: "حد النتائج (افتراضي 40، أقصى 200)", type: "number", required: false },
      ],
      implementation: async (pattern, limit) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "search_code", pattern, limit }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "describe_runtime",
      description: "عرض قدرات الوكيل والأدوات المتاحة وإصدار بايثون",
      argumentAnnotations: [],
      implementation: async () => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "describe_runtime" }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotActionable(
    {
      name: "resolve_intent",
      description: "تشغيل مصنّف النية (intent_resolver) مع تشخيص اختياري",
      argumentAnnotations: [
        { name: "diagnostic", description: "كائن vitals/findings اختياري", type: "object", required: false },
      ],
      implementation: async (diagnostic) => {
        const res = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "resolve_intent", diagnostic }),
        });
        return await res.json();
      },
    },
    []
  );

  useMakeCopilotReadable("dashboard_status", "لوحة التحكم نشطة، الأدوات متاحة عبر tool_server.py والـ LLM محلي (Ollama).");
  return null;
}

function ChatUI() {
  const { visibleMessages, append, isLoading, input, setInput, stop } = useBglCopilotChat({ id: "bgl-copilot" });
  const [pending, setPending] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    if (!input.trim()) return;
    setPending(true);
    await append({ role: "user", content: input });
    setInput("");
    setPending(false);
  };

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "12px", width: "100%" }}>
      <div
        style={{
          border: "1px solid #223047",
          borderRadius: "10px",
          padding: "12px",
          minHeight: "320px",
          maxHeight: "520px",
          overflowY: "auto",
          background: "#0f172a",
        }}
      >
        {visibleMessages.length === 0 ? (
          <p style={{ color: "#9fb3c8" }}>ابدأ بالسؤال أو اطلب فحصاً.</p>
        ) : (
          visibleMessages.map((m, idx) => (
            <div key={idx} style={{ marginBottom: "8px", color: m.role === "assistant" ? "#e2e8f0" : "#7dd3fc" }}>
              <strong style={{ marginRight: "6px" }}>{m.role === "assistant" ? "الوكيل" : "أنت"}:</strong>
              <span>{m.content}</span>
            </div>
          ))
        )}
      </div>
      <form onSubmit={submit} style={{ display: "flex", gap: "8px" }}>
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="اكتب بالعربية، مثل: شغّل run_checks ثم لخص النتائج."
          style={{
            flex: 1,
            padding: "10px",
            borderRadius: "8px",
            border: "1px solid #1e293b",
            background: "#0b1220",
            color: "#e2e8f0",
          }}
        />
        <button
          type="submit"
          disabled={isLoading || pending}
          style={{
            padding: "10px 14px",
            borderRadius: "8px",
            border: "1px solid #22d3ee",
            background: "#06b6d4",
            color: "#0b1220",
            cursor: "pointer",
            minWidth: "80px",
          }}
        >
          {isLoading || pending ? "..." : "إرسال"}
        </button>
        {isLoading && (
          <button
            type="button"
            onClick={() => stop()}
            style={{
              padding: "10px 12px",
              borderRadius: "8px",
              border: "1px solid #f97316",
              background: "#f59e0b",
              color: "#0b1220",
              cursor: "pointer",
            }}
          >
            إيقاف
          </button>
        )}
      </form>
      <p style={{ color: "#64748b", fontSize: "12px" }}>
        يعمل عبر Ollama المحلي و tool_server.py؛ لا حاجة لـ CDN أو Docker.
      </p>
    </div>
  );
}

function ContextBridge() {
  const [ctx, setCtx] = useState("... جاري تحميل سياق النظام ...");
  const [logic, setLogic] = useState("... جاري تحميل منطق العمل ...");
  const [domainMap, setDomainMap] = useState("... جاري تحميل domain_map ...");
  const [toolsText, setToolsText] = useState("... جاري تحميل الأدوات ...");

  useMakeCopilotReadable("domain_context", ctx);
  useMakeCopilotReadable("logic_reference", logic);
  useMakeCopilotReadable("domain_map", domainMap);
  useMakeCopilotReadable("tools_list", toolsText);
  useMakeCopilotReadable("context_summary", [ctx, logic, domainMap].join("\n\n"));

  useEffect(() => {
    (async () => {
      try {
        const snap = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "context_snapshot" }),
        }).then((r) => r.json());
        if (snap?.context) setCtx(snap.context);

        const logicFile = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "read_file", path: "docs/logic_reference.md", max_bytes: 12000 }),
        }).then((r) => r.json());
        if (logicFile?.content) setLogic(logicFile.content);

        const domainFile = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "read_file", path: "docs/domain_map.yml", max_bytes: 8000 }),
        }).then((r) => r.json());
        if (domainFile?.content) setDomainMap(domainFile.content);

        const desc = await fetch(TOOL_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "describe_runtime" }),
        }).then((r) => r.json());
        if (desc?.runtime?.tools) {
          setToolsText(desc.runtime.tools.join(", "));
        }
      } catch {
        // ignore
      }
    })();
  }, []);

  return null;
}

function App() {
  return (
    <>
      <ContextBridge />
      <ToolRegistry />
      <ChatUI />
    </>
  );
}

const mount = () => {
  const el = document.getElementById("copilot-root");
  if (!el) return;
  const root = ReactDOM.createRoot(el);
  root.render(
    <React.StrictMode>
      <CopilotProvider>
        <App />
      </CopilotProvider>
    </React.StrictMode>
  );
};

mount();
