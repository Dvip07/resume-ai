# Playwright's own image, because it ships Chromium plus the ~40 system libraries
# a headless browser needs. Building this on a plain node image means chasing
# those libraries by hand on every base-image bump.
FROM mcr.microsoft.com/playwright:v1.55.0-jammy

ENV NODE_ENV=production \
    AUTOMATION_WORKER_HOST=0.0.0.0 \
    AUTOMATION_WORKER_PORT=8081

WORKDIR /app

COPY package.json ./
# The browsers are already in the image, so skip the postinstall download.
RUN npm install --omit=dev --ignore-scripts

COPY src ./src

# Runs as the image's non-root `pwuser`.
USER pwuser

EXPOSE 8081

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.AUTOMATION_WORKER_PORT||8081)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "src/server.js"]
