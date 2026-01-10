# BGL3 GitHub Repository Setup Guide

This guide will help you configure the GitHub repository with all free features.

---

## ✅ Checklist

Use this checklist to track your progress:

### Repository Files

- [x] README.md created
- [x] GitHub Actions workflow created
- [ ] .gitignore updated
- [ ] LICENSE added (if needed)

### GitHub Settings (Web Interface)

- [ ] Issues enabled with labels
- [ ] Pull Request workflow configured
- [ ] Branch protection enabled
- [ ] Wiki enabled
- [ ] Discussions enabled
- [ ] Projects board created
- [ ] Dependabot enabled

---

## 1️⃣ Issues Setup

### Enable Issues

1. Go to **Settings** → **General**
2. Scroll to **Features**
3. ✅ Check **Issues**

### Create Labels

Go to **Issues** → **Labels** → **New label**

Create these labels:

| Label | Color | Description |
|-------|-------|-------------|
| `bug` | #d73a4a | Something isn't working |
| `feature` | #0075ca | New feature request |
| `improvement` | #a2eeef | Enhancement to existing feature |
| `documentation` | #0075ca | Documentation improvements |
| `decision` | #d4c5f9 | Technical decision needed |
| `priority-high` | #e99695 | High priority |
| `priority-low` | #d4c5f9 | Low priority |
| `good first issue` | #7057ff | Good for newcomers |

---

## 2️⃣ Pull Requests Workflow

### Enable PR-only workflow

1. Go to **Settings** → **Branches**
2. Click **Add branch protection rule**
3. Branch name pattern: `main` (or `master`)
4. Enable:
   - ✅ **Require a pull request before merging**
   - ✅ **Require approvals**: 1 (or 0 if you're solo)
   - ✅ **Dismiss stale PR approvals when new commits are pushed**

---

## 3️⃣ Branch Protection

### Protect main branch

In the same **Branch protection rule**:

Enable:

- ✅ **Require status checks to pass before merging**
  - Search and add: `PHP Syntax Check`
  - Search and add: `Project Structure Validation`
- ✅ **Require branches to be up to date before merging**
- ✅ **Do not allow bypassing the above settings**
- ✅ **Restrict who can push to matching branches** (optional)
- ✅ **Allow force pushes**: ❌ (disabled)
- ✅ **Allow deletions**: ❌ (disabled)

Click **Create** or **Save changes**

---

## 4️⃣ GitHub Actions

### Verify workflow

1. Go to **Actions** tab
2. You should see "PHP Lint & Basic Checks"
3. It will run automatically on every PR and push to main

### Test it

```bash
git checkout -b test/github-actions
echo "# Test" >> README.md
git add README.md
git commit -m "Test: GitHub Actions"
git push origin test/github-actions
```

Then create a PR and watch the checks run!

---

## 5️⃣ Wiki Setup

### Enable Wiki

1. Go to **Settings** → **General** → **Features**
2. ✅ Check **Wikis**

### Create initial pages

Go to **Wiki** tab → **Create the first page**

#### Page 1: Home

```markdown
# BGL3 Wiki

Welcome to the BGL3 documentation wiki!

## Pages

- [Architecture](Architecture) - System architecture overview
- [Design System](Design-System) - UI/UX design system
- [AI Matching](AI-Matching) - AI-powered matching system
- [API Reference](API-Reference) - API endpoints documentation
- [Decisions](Decisions) - Technical decisions log
```

#### Create these pages (empty for now)

- **Architecture**: System architecture overview
- **Design-System**: UI/UX documentation
- **AI-Matching**: AI matching algorithm
- **API-Reference**: API endpoints
- **Decisions**: Technical decisions log

---

## 6️⃣ Discussions

### Enable Discussions

1. Go to **Settings** → **General** → **Features**
2. ✅ Check **Discussions**

### Create Categories

Go to **Discussions** → **Categories** (gear icon)

Recommended categories:

- 📣 **Announcements** - Important updates
- 💡 **Ideas** - Feature suggestions
- 🙏 **Q&A** - Questions and answers
- 🗳️ **Polls** - Community polls
- 💬 **General** - General discussions

---

## 7️⃣ Projects

### Create Project Board

1. Go to **Projects** tab
2. Click **New project**
3. Choose **Board** template
4. Name: "BGL3 Development"

### Configure columns

Rename columns to:

1. **📋 To Do** - Planned tasks
2. **🚧 In Progress** - Currently working on
3. **✅ Done** - Completed

### Auto-add items

In Project settings:

- ✅ Auto-add new issues
- ✅ Auto-add new pull requests

---

## 8️⃣ README

✅ Already created! (`README.md`)

Verify it looks good:

1. Go to repository home page
2. Scroll down to see README
3. Check links work (they'll work after Wiki is created)

---

## 9️⃣ Dependabot

### Enable Dependabot

1. Go to **Settings** → **Security** → **Code security and analysis**
2. Enable:
   - ✅ **Dependabot alerts**
   - ✅ **Dependabot security updates**

### Optional: Dependabot version updates

Create `.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "weekly"
```

---

## 🎯 Final Checklist

After completing all steps:

- [ ] Create a test issue
- [ ] Create a test PR (from a branch)
- [ ] Verify Actions run
- [ ] Verify PR can't merge without checks
- [ ] Post in Discussions to test
- [ ] Add an item to Project board
- [ ] Check Dependabot is monitoring

---

## 🚀 You're Done

Your repository is now professionally configured! 🎉

### Next Steps

1. Start working in branches
2. Use Issues to track work
3. Create PRs for all changes
4. Watch the automation work!

---

**Need help?** Open a Discussion or Issue!
