# ✨ AI Resume Generator Portal (Powered by Ollama & Laravel)

```
      _____  _                 _                               
     |  __ \| |               | |                              
     | |__) | |__   ___   ___ | | ___   __ _ _ __   ___  ___   
     |  ___/| '_ \ / _ \ / _ \| |/ _ \ / _` | '_ \ / _ \/ __|  
     | |    | | | | (_) | (_) | | (_) | (_| | | | |  __/\__ \  
     |_|    |_| |_|\___/ \___/|_|\___/ \__, |_| |_|\___||___/  
                                        __/ |                 
                                       |___/                  
```

Welcome to the **AI-Powered Resume Builder** that does the hard work for you.

Upload your resume.  
Get AI-suggested roles.  
Click apply — get a tailored resume.  
**Just 3 clicks**. That’s it.

> "Let AI write your resume while you drink chai."

---

## 🚀 Features (Completed)

✅ **User Auth (Login & Register)**  
✅ **Resume Upload (PDF)**  
✅ **PDF → Text Extraction**  
✅ **Ollama Integration** for analyzing the resume and suggesting top-fitting roles  
✅ **Job Listings Per Role** using **Adzuna API**  
✅ **Job Click Tracking** (when clicked → saved in DB)  
✅ **"Apply" Triggers a Smart Crawler** that fetches full job descriptions using headless browser  
✅ **Jobs + Resumes Stored in Database**

---

## 🥪 Still Cooking (In Progress)

🔄 **Background Crawler as a Scheduled Cron Job**  
  → Automatically updates job descriptions every hour (chunked, smart crawl detection)  
🧠 **LLM-based Resume Tailoring**  
  → Sends job desc + old resume text to Ollama to generate a custom-fit resume  
📄 **Resume JSON → PDF**  
  → Smart insertion of new content and formatting using a PDF library  
📅 **Downloadable Resume Output**  
💼 **My Jobs Tracker**  
  → Keeps history of applied jobs and related resumes

---

## 🛠️ Tech Stack

- **Backend:** Laravel  
- **Frontend:** Blade + Tailwind (can be swapped)  
- **LLM Engine:** Ollama (local LLM like LLaMA3)  
- **Crawler:** Symfony Panther (headless Chrome control)  
- **PDF Library:** TBD (DomPDF / SnappyPDF or custom)  
- **Job API:** Adzuna (LinkedIn coming later)  
- **Database:** MySQL  
- **Scheduler:** Laravel Scheduler (for crawler)

---

## 📦 Installation & Setup

### 1. Clone the Repo
```bash
git clone https://github.com/dvip-ai/ai-resume-portal.git
cd ai-resume-portal
```

### 2. Install Dependencies
```bash
composer install
npm install && npm run dev
```

### 3. Set Up .env File
Copy `.env.example` to `.env` and configure:
```env
DB_*
OLLAMA_API_KEY=
ADZUNA_APP_ID=
ADZUNA_APP_KEY=
```

### 4. Set up ChromeDriver (for Panther)
Place ChromeDriver binary under:
```bash
base_path('drivers/chromedriver')
```
Or install via Homebrew:
```bash
brew install chromedriver
```

### 5. Run Migrations
```bash
php artisan migrate
```

### 6. Start Your Local Server
```bash
php artisan serve
```

---

## 🔄 Cron Job (Coming Soon)
To auto-update job descriptions in the background:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## 🧑‍💻 Contributing (Coming Once Open Source)

- Raise an issue first if you're fixing a bug or adding a feature  
- PRs welcome — especially new selectors or PDF beautification logic  
- All contributors will be listed in the `CONTRIBUTORS.md` file  
- Style guide and dev container coming soon

---

## 💡 Future Ideas

- Add optional **role preference filter**
- Support for **multi-language resumes**
- ATS score simulator (coming from Ollama)
- React/Vue frontend switch
- Resume *version comparison* panel
- Deployable via **Docker**, **Forge**, or **Vercel backend**

---

## ✨ Author

**Built by [Dvip Patel](https://github.com/dvip-ai)**  
> Master's student | SaaS dev ninja | Requirements whisperer | Part-time philosopher at 3AM

---

## 🌶️ Real Talk

This ain’t your typical resume builder.

This is **LLM-powered, auto-crawling, cron-scheduled**, PDF-slinging, three-click **AI job-seeking sorcery** built by one man who runs on chai, deadlines, and divine chaos.

If you’re:
- Tired of updating your resume manually 🤬  
- Applying to jobs with the same generic doc 🥱  
- Searching for roles like it's 2012 🕵️‍♂️  

Then you just found your fix.

**Open-source. Free. Blazing fast. Built with purpose.**

> *Built for devs. Loved by AI. Feared by LinkedIn modals.*

---

🔥 *Star it. Fork it. Use it. Break it. Rebuild it. Make it your own.*  
Let’s help the world apply smarter — not harder.
