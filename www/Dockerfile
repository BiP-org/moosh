FROM ruby:3.0-slim

ENV BUNDLE_PATH=/usr/local/bundle \
    BUNDLE_BIN=/usr/local/bundle/bin \
    GEM_HOME=/usr/local/bundle

# Install system dependencies for native extensions (rdiscount, ffi, etc.)
RUN apt-get update -qq && apt-get install -y \
    build-essential \
    zlib1g-dev \
    libyaml-dev \
    libffi-dev \
    nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/jekyll

# We do NOT copy files here. The host directory will be mounted at runtime.
# However, we still need to install gems. 
# To do this, we need the Gemfile and Gemfile.lock to be available.
# Since we are mounting the volume, we can run bundle install inside the container 
# pointing to the mounted volume, OR we copy just the lock file to install deps first.

# STRATEGY: Copy ONLY Gemfile and Gemfile.lock to install dependencies, 
# then mount the rest of the source code.
COPY Gemfile Gemfile.lock ./

RUN bundle install

# The rest of the site files will be mounted at runtime
CMD ["jekyll", "build"]