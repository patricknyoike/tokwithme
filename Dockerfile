FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions for WebSocket and process control
RUN docker-php-ext-install pcntl sockets

# Set working directory
WORKDIR /app

# Copy all files
COPY . /app/

# Make sure server.php is executable
RUN chmod +x server.php

# Expose port 5008
EXPOSE 5008

# Run the WebSocket server with proper error logging
CMD ["php", "-d", "display_errors=stderr", "server.php"]
