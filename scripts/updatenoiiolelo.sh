#!/usr/bin/bash

# Check if there are new documents to index at a few sites that get new content periodically

PARSERS="keaolama kaulanapilina kauakukalahale"
# All providers
PROVIDERS="mysql postgres elasticsearch opensearch"
# Providers that do not get grammar analysis during ingestions
GRAMMAR_PROVIDERS="mysql postgres"

# Full log
LOG="/tmp/noiiolelo.log"
# Summary of log
TMPLOG="/tmp/tmplog"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOSTNAME=$(hostname -f)
SUBJECT="Noiilolelo indexing on $HOSTNAME"
SENDER=robw@worldspot.com
RECIPIENT=robw@worldspot.com

initialize() {
    rm -f $LOG $TMPLOG
    touch $LOG $TMPLOG
    chmod 666 $LOG $TMPLOG
}

getUpdates() {
    echo "Noiiolelo updates" > $LOG
    for f in $PARSERS; do
        # Get the doc list once
        cd $DIR; php save.php --parser=$f  --doclist-only --doclist-save=/tmp/$f 2>&1 | tee -a $LOG
        for provider in $PROVIDERS; do
            echo "$provider save $f"
            cd $DIR; php save.php --provider=$provider --parser=$f --doclist-file=/tmp/$f 2>&1 | tee -a $LOG
            echo "" | tee -a $LOG
        done
    done
}

updateGrammarPatterns() {
    # Update the grammatical patterns from the sentences
    for provider in $GRAMMAR_PROVIDERS; do
        echo "$provider grammar patterns" | tee -a $LOG
        cd $DIR; php populate_grammar_patterns.php --provider=$provider | tee -a $LOG
    done
}

summarize() {
    grep Summary: $LOG > $TMPLOG
    echo "" >> $TMPLOG
    grep -A 20 'Sentences newly analyzed' $LOG >> $TMPLOG
}

sendReport() {
    if command -v aws >/dev/null 2>&1; then
        [[ -f ~/.bash-functions ]] && source ~/.bash-functions
        send_ses_email "$SENDER" "$RECIPIENT" $TMPLOG "$SUBJECT"
    else
        cat $TMPLOG | mail -r "$SENDER" -s "$SUBJECT" "$RECIPIENT"
    fi
}

initialize
getUpdates
updateGrammarPatterns
summarize
sendReport



