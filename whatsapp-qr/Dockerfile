FROM node:22-bookworm-slim
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY *.mjs ./
USER node
EXPOSE 3000
CMD ["node", "server.mjs"]
