import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "path";

export default defineConfig({
  plugins: [react()],
  build: {
    lib: {
      entry: resolve(__dirname, "src/main.jsx"),
      name: "copilotWidget",
      formats: ["iife"],
      fileName: () => "copilot-widget.js",
    },
    outDir: "app/copilot/dist",
    emptyOutDir: true,
  },
});
