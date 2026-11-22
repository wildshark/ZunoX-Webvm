# WebVM login shell defaults.
if [ -n "${TENANT_NAME:-}" ]; then
  export PS1="\[\e[38;5;45m\]\u@${TENANT_NAME}/\h\[\e[0m\]:\[\e[38;5;222m\]\w\[\e[0m\]\$ "
else
  export PS1='\[\e[38;5;45m\]\u@\h\[\e[0m\]:\[\e[38;5;222m\]\w\[\e[0m\]\$ '
fi
export EDITOR="${EDITOR:-vi}"
export PAGER="${PAGER:-less}"

alias ll='ls -alF'
alias la='ls -A'
alias grep='grep --color=auto'
