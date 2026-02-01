import asyncio
import json
import sys
import os
from pathlib import Path
from playwright.async_api import async_playwright

# Setup Paths
ROOT_DIR = Path(__file__).parent.parent
sys.path.append(str(ROOT_DIR))

# Import Brain Components
try:
    from .bgl_core.brain.inference import InferenceEngine
    from .bgl_core.brain.perception import capture_local_context
except ImportError:
    # Fallback for script execution
    sys.path.append(str(ROOT_DIR / ".bgl_core" / "brain"))
    from inference import InferenceEngine
    from perception import capture_local_context

# Initialize Brain
engine = InferenceEngine(Path(".bgl_core/brain/knowledge.db"))
# Force Local Model
os.environ["LLM_MODEL"] = "llama3.1"


async def get_interactive_elements(page):
    """
    Scans the page for interactive elements (buttons, links, inputs)
    and returns a simplified text representation for the AI.
    """
    # Simple extraction script
    elements = await page.evaluate("""() => {
        const items = [];
        document.querySelectorAll('button, a, input, select').forEach((el, index) => {
            const rect = el.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0 && window.getComputedStyle(el).visibility !== 'hidden') {
                let label = el.innerText || el.placeholder || el.name || el.id || 'Unlabeled';
                label = label.substring(0, 50).replace(/\\n/g, ' ');
                items.push({
                    id: index,
                    tag: el.tagName.toLowerCase(),
                    label: label,
                    selector: el.id ? '#' + el.id : el.className ? '.' + el.className.split(' ')[0] : el.tagName.toLowerCase()
                });
            }
        });
        return items;
    }""")
    return elements


async def think(goal: str, elements: list) -> dict:
    """
    Sends the current state and goal to Llama 3.1 to decide the next action.
    """
    # Create a clean context prompt
    context_str = "\n".join(
        [f"[{el['id']}] <{el['tag']}> {el['label']}" for el in elements[:30]]
    )  # Limit to 30 items for speed

    prompt = f"""
    You are an autonomous browser agent. Your goal is: "{goal}".
    
    Current Visible Elements:
    {context_str}
    
    Instructions:
    1. Select the single best element to interact with to achieve the goal.
    2. If the goal is achieved (e.g. we are on the right page), return action "FINISH".
    3. Return valid JSON ONLY. Format: {{ "action": "click"|"type", "element_id": <id>, "reason": "brief reason" }}
    """

    print(f"\n🧠 Thinking... (Context: {len(elements)} elements)")
    resp = engine._query_llm(prompt)

    try:
        content = resp.get("choices", [{}])[0].get("message", {}).get("content", "")
        # Clean markdown if present
        json_str = content.replace("```json", "").replace("```", "").strip()
        decision = json.loads(json_str)
        return decision
    except Exception as e:
        print(f"❌ Thinking failed: {e}")
        return {}


async def run_smart_agent(start_url: str, goal: str):
    print(f"🚀 Launching Smart Agent...")
    print(f"🎯 Goal: {goal}")
    print(f"🌍 URL: {start_url}")

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        # Create context with video recording
        video_dir = ROOT_DIR / "storage" / "logs" / "playwright_video"
        video_dir.mkdir(parents=True, exist_ok=True)
        context = await browser.new_context(
            record_video_dir=video_dir, record_video_size={"width": 1280, "height": 720}
        )
        page = await context.new_page()

        await page.goto(start_url)
        await page.wait_for_load_state("networkidle")

        # Inject Visual Cursor
        await page.evaluate("""() => {
            const cursor = document.createElement('div');
            cursor.id = 'ai-cursor';
            cursor.style.position = 'absolute';
            cursor.style.width = '20px';
            cursor.style.height = '20px';
            cursor.style.backgroundColor = 'rgba(255, 0, 0, 0.7)';
            cursor.style.borderRadius = '50%';
            cursor.style.zIndex = '999999';
            cursor.style.pointerEvents = 'none';
            cursor.style.transition = 'all 0.5s cubic-bezier(0.25, 0.1, 0.25, 1)';
            cursor.style.boxShadow = '0 0 10px rgba(255, 0, 0, 0.5)';
            cursor.style.border = '2px solid white';
            document.body.appendChild(cursor);
        }""")

        for step in range(5):  # Limit to 5 steps for safety
            print(f"\n--- Step {step + 1} ---")

            # 1. Perception
            elements = await get_interactive_elements(page)
            print(f"👁️ Perceived {len(elements)} interactive elements.")

            # 2. Reasoning
            decision = await think(goal, elements)

            if not decision:
                print("🤷 AI is confused. Stopping.")
                break

            print(f"💡 Decision: {decision}")

            if decision.get("action") == "FINISH":
                print("✅ Goal Achieved!")
                break

            # 3. Action
            target_id = decision.get("element_id")
            target_el = next((el for el in elements if el["id"] == target_id), None)

            if target_el:
                try:
                    # Move Cursor Visually
                    await page.evaluate(
                        f"""(index) => {{
                        const el = document.querySelectorAll('button, a, input, select')[index];
                        if(el) {{
                            const rect = el.getBoundingClientRect();
                            const x = rect.left + window.scrollX + rect.width/2;
                            const y = rect.top + window.scrollY + rect.height/2;
                            const cursor = document.getElementById('ai-cursor');
                            if(cursor) {{
                                cursor.style.left = (x - 10) + 'px'; // Center 20px cursor
                                cursor.style.top = (y - 10) + 'px';
                            }}
                        }}
                    }}""",
                        target_id,
                    )

                    print(f"👀 Moving cursor to element {target_id}...")
                    await page.wait_for_timeout(1000)  # Wait for animation

                    # Click
                    await page.evaluate(
                        f"""(index) => {{
                        const el = document.querySelectorAll('button, a, input, select')[index];
                        if(el) {{
                            // Click effect
                            const cursor = document.getElementById('ai-cursor');
                            if(cursor) {{
                                cursor.style.transform = 'scale(0.8)';
                                setTimeout(() => cursor.style.transform = 'scale(1)', 150);
                            }}
                            el.click();
                        }}
                    }}""",
                        target_id,
                    )

                    print(f"🖱️ Clicked element {target_id}")
                    await page.wait_for_timeout(2000)  # Wait for reaction

                except Exception as e:
                    print(f"❌ Action failed: {e}")
            else:
                print("❌ Invalid target ID from AI.")

        await browser.close()


if __name__ == "__main__":
    # Default Goal: Navigate to dashboard or settings
    goal = "Navigate to the Import page"
    url = "http://localhost:8000"

    if len(sys.argv) > 1:
        goal = sys.argv[1]

    asyncio.run(run_smart_agent(url, goal))
