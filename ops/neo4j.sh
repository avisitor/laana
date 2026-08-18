#!/usr/bin/env bash
set -euo pipefail

CONTAINER="neo4j"
IMAGE="neo4j:5"
PORT_HTTP=7474
PORT_BOLT=7687
NEO4J_PASSWORD="noiioleloNeo4j123"

usage() {
    echo "Usage: $0 {start|status|stop}"
    exit 1
}

cmd_start() {
    if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "Container '${CONTAINER}' already exists."
        if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
            echo "It is already running."
        else
            echo "Starting it..."
            docker start "$CONTAINER"
        fi
    else
        echo "Starting Neo4j..."
        docker run -d \
            --name "$CONTAINER" \
            -p "${PORT_HTTP}:${PORT_HTTP}" \
            -p "${PORT_BOLT}:${PORT_BOLT}" \
            -e NEO4J_AUTH=neo4j/"${NEO4J_PASSWORD}" \
            "$IMAGE"
    fi
    echo "Neo4j HTTP: http://localhost:${PORT_HTTP}"
    echo "Neo4j Bolt: bolt://localhost:${PORT_BOLT}"
}

cmd_status() {
    if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "Neo4j is running."
        docker ps --filter "name=^${CONTAINER}$" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    elif docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "Neo4j container exists but is stopped."
    else
        echo "Neo4j is not installed."
    fi
}

cmd_stop() {
    if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "Stopping Neo4j..."
        docker stop "$CONTAINER"
        echo "Stopped."
    else
        echo "Neo4j is not running."
    fi
}

[ $# -eq 0 ] && usage

case "$1" in
    start)  cmd_start ;;
    status) cmd_status ;;
    stop)   cmd_stop ;;
    *)      usage ;;
esac
