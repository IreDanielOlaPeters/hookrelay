FROM node:25-alpine
WORKDIR /app
COPY package.json server.js ./
USER node
EXPOSE 4000
CMD ["node", "server.js"]
