FROM ghcr.io/vkoori/php_node:24.18.0-bookworm-slim

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY . .

CMD ["npm", "run", "scrape"]
